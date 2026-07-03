---
document_type: security-review
review_type: plan
assessment_date: 2026-07-03
codebase_analyzed: acrossai-mcp-manager (Feature 012 — MCP Settings Tab + CLI Auth Log admin page removal)
total_files_analyzed: 6
total_findings: 8
overall_risk: LOW
critical_count: 0
high_count: 0
medium_count: 0
low_count: 3
informational_count: 5
owasp_categories: [A02, A05, A08, A09]
cwe_ids: [CWE-284, CWE-311, CWE-693, CWE-778, CWE-829, CWE-915, CWE-1035, CWE-1284]
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

# Security Review — Plan Artifact (Feature 012 / mcp-settings-tab)

## Executive Summary

Feature 012 delivers two coordinated changes: (1) a new `SettingsMenu` class at `admin/Partials/SettingsMenu.php` registers an "MCP" tab (priority 20) on the shared `?page=acrossai-settings` page owned by the `acrossai-co/main-menu` vendor package, with three toggles persisted via the WordPress Settings API; (2) the standalone "CLI Auth Log" admin submenu at `?page=acrossai_mcp_manager_cli_auth_log` is retired, deleting 4 files/blocks while preserving every file under `includes/Database/CliAuthLog/**` for OAuth flow runtime consumption.

**Uninstall behavior change**: the pre-Feature-012 `uninstall.php` unconditionally drops the two OAuth tables + their `db_version` options + the OAuth-cleanup cron (Feature 006 destructive-by-nature scope). Feature 012 migrates that behavior behind a new `acrossai_mcp_uninstall_delete_data` opt-in flag (int 0/1, default 0). The new default is **preserve everything** — matching WP.org guideline 5 (never delete user data without explicit consent) + the sibling `acrossai-abilities-manager` pattern.

Three pre-existing security invariants explicitly survive this feature and are enforced by the plan:

1. **SEC-001 atomic-CAS** on `CliAuthLog\Query::redeem_atomic` (Feature 011 FR-006) — the `WHERE id = %d AND completed_at IS NULL` predicate is preserved by DB-layer non-deletion (Feature 012 spec FR-028).
2. **SHA-256 hashed-column widths** (`char(64)` on `auth_code_hash` + `access_token_hash`; `char(43)` on `code_challenge`) — untouched, no schema changes.
3. **`$wpdb->prepare()` on every DB path** — the rewritten `uninstall.php` LIKE-sweep uses `$wpdb->prepare()` (spec FR-020, FR-022); `DROP TABLE IF EXISTS` loop has a scoped `phpcs:ignore` because `$table` is derived from `$wpdb->prefix` + hardcoded strings (spec FR-021).

**Overall risk: LOW** — three LOW findings and five INFORMATIONAL findings. Zero CRITICAL, HIGH, or MEDIUM. The three LOW findings are:
- **SEC-012-001** — uninstall-default behavior change requires operator awareness (changelog is the sole announcement channel).
- **SEC-012-002** — `includes/Database/CliAuthLog/**` preservation depends on grep discipline at TASK-6 review time.
- **SEC-012-003** — `phpcs:ignore` scoping on the DROP TABLE loop requires reviewer discipline.

Two INFO findings surface follow-up considerations (vendor-package hard-require re-evaluation trigger; missing PHPUnit assertion for uninstall preserve-by-default). Three INFO findings document architectural precedents (first A9 subtractive edit; vendor `PageRenderer` supply-chain trust; escape idiom for URL-containing HTML notices).

The plan is implementable as-written. All eight findings are advisory or code-review-time gates; none block the plan → tasks → implement chain.

## Plan Artifacts Reviewed

| Path | Notes |
|---|---|
| `specs/012-mcp-settings-tab/spec.md` | 4 US + 33 FRs + 7 SCs + Behavior Change disclosure + 9 edge cases + Assumptions |
| `specs/012-mcp-settings-tab/plan.md` | Implementation plan; 7 task groups T1..T7; concrete decisions locked; constitution check PASS |
| `specs/012-mcp-settings-tab/memory-synthesis.md` | 5 decisions + 5 arch constraints + 2 deviations + 3 security constraints + 2 bug patterns within budget |
| `specs/012-mcp-settings-tab/security-constraints.md` | Governed-plan orchestrator output (2026-07-03) — this document extends with formal SEC-### findings |
| `specs/012-mcp-settings-tab/architecture-violations.md` | Zero HARD arch violations; 3 SOFT items (A9 subtractive precedent, DEV1 narrowing, D4 vendor-class narrowing) all captured in planned DEC entries |
| `docs/planings-tasks/012-mcp-settings-tab.md` | Source planning doc — 7 TASKs + Speckit Workflow block + CONSTRAINTS |

Cross-referenced: `.specify/memory/constitution.md` §III (Security First — NON-NEGOTIABLE) + Feature 007 consent-surface exception amendment; `docs/memory/INDEX.md` (D6, D15, DEV1, DEV4, S3, S4, S6, B1, B15 selected); vendor package README section 133-207 (tab registration contract); sibling reference `../acrossai-abilities-manager/admin/Partials/SettingsMenu.php:1-221`; prior security reviews `docs/security-reviews/2026-07-02-{010-composer-dependencies,011-berlindb-migration}-plan.md` for inherited supply-chain + SEC-001 invariants.

## Vulnerability Findings

### SEC-012-001 — Uninstall-default behavior change requires operator awareness; changelog is the sole announcement channel

- **Severity**: LOW
- **CVSS v3.1**: 3.7 (`AV:L/AC:H/PR:H/UI:R/S:U/C:N/I:L/A:L`)
- **OWASP Category**: A05:2025-Security Misconfiguration
- **CWE**: CWE-1284 (Improper Validation of Specified Quantity in Input — analog for "operator's expectation of default behavior")
- **Location**: `spec.md` FR-023 + `uninstall.php` post-TASK-5 (behavior change from destructive-by-default to preserve-by-default)
- **Spec-Kit task**: TASK-SEC-012-001

**Problem.** The pre-Feature-012 `uninstall.php` unconditionally dropped `wp_acrossai_mcp_oauth_tokens`, `wp_acrossai_mcp_oauth_audit`, and their two `db_version` options + the `acrossai_mcp_oauth_cleanup` cron on uninstall. Post-Feature-012, none of these operations run unless the operator has explicitly ticked the new "Delete all data on uninstall" checkbox in the MCP settings tab.

If a site's operational runbook or backup strategy depends on the pre-Feature-012 destructive default (e.g., "we uninstall the plugin to force OAuth token wipe as part of quarterly credential rotation"), Feature 012 silently subverts that runbook. Sites that fail to notice the changelog + then uninstall + then reinstall will find OAuth tokens still present.

**Plan mitigations.** Spec FR-023 mandates disclosure in the Unreleased changelog. Spec FR-032 mandates a specific bullet naming the behavior change. Plan §Summary + memory-synthesis Conflict Warnings both call it out. `DEC-UNINSTALL-OPT-IN-GATE` (planned TASK-7 capture) documents the durable rule.

**Residual risk.** Changelogs are not always read. Operators who upgraded via WP admin auto-update won't see the changelog until they read `README.txt` manually. WP.org plugin directory doesn't proactively surface breaking behavior changes.

**Remediation.**
1. In addition to the changelog, consider a one-time admin_notice on first admin page load after the Feature 012 upgrade, explaining the new default + linking to the settings tab. Similar to the "the plugin is disabled" pattern used elsewhere in WordPress ecosystem. Optional; adds a hook + a dismissal-transient wiring line but improves operator visibility. Not required for merge but recommended.
2. Document the behavior change in `docs/planings-tasks/README.md` under a "Breaking Changes" section (if one exists) or add a new section.
3. Consider an entry in `docs/memory/BUGS.md` under a "behavioral-drift" tag capturing the pattern: "silent default change in uninstall/teardown paths is a class of operator-surprise bug." Defer to a future memory-hygiene pass.

### SEC-012-002 — `includes/Database/CliAuthLog/**` preservation depends on grep discipline at TASK-6 review time

- **Severity**: LOW
- **CVSS v3.1**: 3.3 (`AV:L/AC:H/PR:H/UI:N/S:U/C:N/I:L/A:L`)
- **OWASP Category**: A09:2025-Security Logging and Monitoring Failures (analog for "loss of security-critical audit trail")
- **CWE**: CWE-693 (Protection Mechanism Failure — this finding covers the risk that the protection is ACCIDENTALLY REMOVED)
- **Location**: `spec.md` FR-028 + `plan.md` §Task Groups T6 gate + spec Pre-flight companion grep
- **Spec-Kit task**: TASK-SEC-012-002

**Problem.** TASK-6 deletes 4 files/blocks under `admin/Partials/` + `includes/Utilities/AdminPageSlugs.php`. The spec explicitly mandates that every file under `includes/Database/CliAuthLog/**` (5 files: Table, Schema, Query, Row, Recorder) is PRESERVED VERBATIM because the OAuth flow consumes them at runtime (Storage.php, BearerAuth.php, CliController.php, Recorder.php callers). If a reviewer misreads TASK-6 as "delete anything with CliAuthLog in the name" and accidentally deletes one of the 5 DB-layer files, OAuth token exchange breaks:

- `redeem_atomic` becomes an undefined method → fatal at the atomic-CAS SEC-001 code path.
- The auth-log audit trail (per constitution §III S3/S4) becomes unrecoverable.
- Feature 011's `AtomicCasTest` regression test would catch this at CI, but only if CI runs before merge — no gate is currently automated.

**Plan mitigations.** Spec FR-028 states the constraint explicitly. Plan §Constitution Check + Task Groups T6 gate document the "companion grep must remain unchanged" check. Security review's `security-constraints.md` §Missing Gates lists this as RECOMMEND #3.

**Residual risk.** The companion grep is a manual check. Without CI automation, a reviewer under time pressure might approve the PR without running it. The failure mode is silent (OAuth flow breaks on first token-redemption attempt, not at merge time).

**Remediation.**
1. Add the companion grep to a CI workflow file (e.g., `.github/workflows/verify-cliauthlog-preservation.yml`) that runs on every PR against `feature/issue-3` and blocks merge if the hit count drops.
2. Alternately, add a PHPUnit test that reflection-instantiates each of the 5 DB-layer classes and asserts they still exist — catches deletion at test time.
3. At minimum, document the required grep in `AGENTS.md` Before Commit Checklist under a "Feature 012 companion greps" section.

### SEC-012-003 — `phpcs:ignore` scoping on the DROP TABLE loop requires reviewer discipline

- **Severity**: LOW
- **CVSS v3.1**: 3.7 (`AV:N/AC:H/PR:L/UI:N/S:U/C:N/I:L/A:N`)
- **OWASP Category**: A05:2025-Security Misconfiguration
- **CWE**: CWE-778 (Insufficient Logging — analog for "suppression comment masking real warnings")
- **Location**: `spec.md` TASK-5 code snippet + `uninstall.php` post-TASK-5 (the `WordPress.DB.PreparedSQL.InterpolatedNotPrepared` ignore)
- **Spec-Kit task**: TASK-SEC-012-003

**Problem.** The rewritten `uninstall.php` DROP TABLE loop uses `$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" )` — table-name interpolation, not `%i` placeholder. This is safe in this specific case because `$table` is derived from `$wpdb->prefix + hardcoded stems` (no user input reaches SQL), but PHPCS's `WordPress.DB.PreparedSQL.InterpolatedNotPrepared` sniff cannot verify that provenance and would flag it. The plan mandates a `phpcs:ignore` comment.

If the `phpcs:ignore` comment is written at file scope (`// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared`) instead of at line/block scope (`// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, ...`) with a `// phpcs:enable` at the end, the sniff is silently suppressed across the entire file. Any future SQL added to `uninstall.php` (or any file that reuses the pattern) would bypass the SQL-injection check.

**Plan mitigations.** Spec TASK-5 code snippet uses `phpcs:ignore` (line-scoped) not `phpcs:disable`. Security review's `security-constraints.md` §Missing Gates RECOMMEND #2 calls this out as a code-review gate.

**Residual risk.** Whether the phpcs:ignore stays line-scoped depends on the implementer's discipline at TASK-5. A well-meaning refactor that adds a second SQL statement below the loop might broaden the ignore to `phpcs:disable`.

**Remediation.**
1. At TASK-5 code-review time, explicitly verify the ignore is either (a) `// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, ...` inline on the specific `$wpdb->query()` line, OR (b) a matching `phpcs:disable`/`phpcs:enable` pair strictly around the loop.
2. Add an inline comment above the ignore explaining WHY the interpolation is safe (`$table` is `$wpdb->prefix + hardcoded stem`).
3. Consider migrating the loop to `%i` placeholder (WordPress 6.2+) which eliminates the ignore entirely: `$wpdb->query( $wpdb->prepare( "DROP TABLE IF EXISTS %i", $table ) )`. This is technically feasible on WP 6.9+ (the plugin's minimum) — worth calling out as a preferred alternative during TASK-5 implementation.

### SEC-012-004 — Vendor hard-require premise underlies `SettingsPage::tab_page_slug()` unconditional call; demotion to optional integration would silently break

- **Severity**: INFORMATIONAL
- **CVSS v3.1**: 2.7 (`AV:L/AC:H/PR:H/UI:N/S:U/C:N/I:N/A:L`)
- **OWASP Category**: A08:2025-Software and Data Integrity Failures
- **CWE**: CWE-829 (Inclusion of Functionality from Untrusted Control Sphere — inverse: reliance on functionality assumed always present)
- **Location**: `spec.md` FR-013 + `plan.md` §Concrete decisions + `memory-synthesis.md` D4 scope-narrowing note
- **Spec-Kit task**: TASK-SEC-012-004

**Problem.** Spec FR-013 mandates that `register_settings()` MUST NOT wrap the `\AcrossAI_Main_Menu\SettingsPage::tab_page_slug()` call in a `class_exists()` guard. Rationale: `acrossai-co/main-menu` is a hard require in `composer.json` (Feature 010 FR-030 P1 pre-activation vendor autoload guard) — the class is guaranteed present at `admin_init`. Adding a guard would be dead code and would deviate from the sibling `acrossai-abilities-manager` pattern.

However, this creates a fragile coupling: if a future feature demotes `acrossai-co/main-menu` from a hard require (e.g., moves it to `suggest` in composer.json to allow the plugin to install without it), Feature 012's `register_settings()` becomes a fatal-error surface at `admin_init` because the class no longer exists. The failure mode is loud (WSOD in wp-admin) but produces a hard-to-diagnose error on production sites.

**Plan mitigations.** Spec CONSTRAINTS block: "Do not add a `class_exists( '\AcrossAI_Main_Menu\SettingsPage' )` guard ... If the package is ever demoted from hard-require to optional integration, the guard becomes mandatory and DEC-VENDOR-SETTINGS-TAB-INTEGRATION must be re-evaluated." Planned DEC entry (TASK-7) codifies the trigger. Memory-synthesis explicitly flags D4 scope narrowing for the vendor-package variant.

**Residual risk.** The trigger is documented in memory but requires a future feature author to READ the DEC entry before demoting the package. Cross-feature memory discipline is imperfect.

**Remediation.**
1. Add a `docs/memory/BUGS.md` cross-reference: when demoting a hard-require to optional integration, search DECISIONS.md for `hard require` mentions and add the guard at every callsite. This is a general-purpose lesson beyond this feature.
2. Consider a `.github/CODEOWNERS` entry that requires review from a maintainer familiar with the vendor-package integration when `composer.json` is edited. Optional.
3. Optionally, add a defensive `class_exists()` guard NOW (contra spec FR-013) with a docblock explaining "belt and suspenders — the P1 activation guard should prevent reaching this code path without the vendor class, but defense-in-depth is cheap." This would be an intentional deviation from sibling pattern — trade-off between family consistency and resilience. Not recommended for this PR; keep as documented follow-up.

### SEC-012-005 — Missing PHPUnit assertion for uninstall preserve-by-default

- **Severity**: INFORMATIONAL
- **CVSS v3.1**: 2.0 (`AV:L/AC:H/PR:H/UI:N/S:U/C:N/I:N/A:L`)
- **OWASP Category**: A09:2025-Security Logging and Monitoring Failures (analog for "no automated regression net on safety-critical default")
- **CWE**: CWE-693 (Protection Mechanism Failure)
- **Location**: `spec.md` TASK-4 (PHPUnit tests) + `plan.md` §Task Groups T4 gate
- **Spec-Kit task**: TASK-SEC-012-005

**Problem.** Feature 012's PHPUnit test file `tests/phpunit/Admin/SettingsMenuTest.php` (planned TASK-4) contains three test methods: `test_register_tab_appends_expected_shape`, `test_register_tab_normalizes_non_array_input`, `test_register_settings_registers_expected_option_keys`. None of these cover the `uninstall.php` preserve-by-default gate (spec FR-019).

The preserve-by-default gate is the LOAD-BEARING safety invariant for the uninstall behavior change (see SEC-012-001). Its correctness is currently verified only via the two manual smoke tests in spec TASK-5's DoD. If a future refactor accidentally inverts the gate (`if ( 1 === (int) get_option(...) ) { return; }`) or removes the early-return altogether, the manual smoke tests would catch it — but only if someone runs them.

**Plan mitigations.** Spec TASK-5 DoD lists the two smoke tests (preserve-by-default + destructive opt-in). Security review's `security-constraints.md` §Missing Gates RECOMMEND #1 suggests adding a PHPUnit assertion.

**Residual risk.** Manual smoke tests are not run on every commit; PHPUnit is.

**Remediation.**
1. At TASK-4 or TASK-5 implementation time, add a fourth test method to `tests/phpunit/Admin/SettingsMenuTest.php` (or a new file `tests/phpunit/UninstallTest.php`) that: (a) sets `WP_UNINSTALL_PLUGIN` via reflection or process-isolated `runInSeparateProcess`, (b) invokes `uninstall.php` via `require`, (c) with option value `0`, asserts no `$wpdb->query()` calls fired (via `$wpdb->queries` inspection) AND no options were deleted. Locks the preserve-by-default invariant.
2. Alternately, use a manual `include`-based test with `WP_UnitTestCase` and monkey-patch `wp_die` to prevent test suicide.
3. If neither is feasible with the current test bootstrap, at minimum add a docblock comment inside `uninstall.php` above the gate explaining WHY the gate order matters + citing spec FR-019.

### SEC-012-006 — First A9 subtractive edit sets precedent for future subtractive edits to the canonical whitelist

- **Severity**: INFORMATIONAL
- **CVSS v3.1**: 2.0 (`AV:L/AC:H/PR:H/UI:R/S:U/C:N/I:L/A:N`)
- **OWASP Category**: A05:2025-Security Misconfiguration
- **CWE**: CWE-284 (Improper Access Control — analog for "future subtractive edits could remove legitimate screen IDs from the enqueue-guard whitelist")
- **Location**: `spec.md` FR-018 + FR-026 + `architecture-violations.md` A9 note
- **Spec-Kit task**: TASK-SEC-012-006

**Problem.** Constitution architecture A9 originally documented `AdminPageSlugs::plugin_screen_ids()` as "additive-only extensions". Feature 012 introduces the FIRST justified subtractive edit — removing 2 entries for the deleted `CLI_AUTH_LOG` slug. This sets a precedent that future features could misapply to REMOVE screen IDs for pages that ARE still live, which would cause the enqueue-guard in `admin/Main.php` to miss those pages → assets not loaded → silent JS/CSS breakage on the affected admin surface.

**Plan mitigations.** Planned `DEC-ADMIN-SURFACE-PRUNE-CLI-AUTH-LOG` entry (TASK-7) documents the rule for future subtractive edits: "allowed ONLY when the corresponding submenu page is removed in the same feature." This is the precedent-setting mitigation.

**Residual risk.** Future feature authors must READ the DEC entry before making subtractive edits. Cross-feature memory discipline.

**Remediation.**
1. When TASK-7 lands the DEC entry in `DECISIONS.md`, add a corresponding tag in `docs/memory/INDEX.md`'s Active Decisions row (e.g., `subtractive-whitelist-rule` in the Tags column) so a search for "whitelist" surfaces this rule immediately.
2. Consider adding a code comment in `AdminPageSlugs.php` above `plugin_screen_ids()` referencing the DEC entry: "// See docs/memory/DECISIONS.md DEC-ADMIN-SURFACE-PRUNE-CLI-AUTH-LOG for subtractive-edit rules."
3. Optionally, add a linting rule / architecture-guard check that flags any PR touching `plugin_screen_ids()` for reviewer attention.

### SEC-012-007 — Vendor `PageRenderer` nonce + option-group handling is trusted supply-chain code

- **Severity**: INFORMATIONAL
- **CVSS v3.1**: 3.0 (`AV:L/AC:H/PR:H/UI:N/S:U/C:L/I:L/A:N`)
- **OWASP Category**: A08:2025-Software and Data Integrity Failures
- **CWE**: CWE-1035 (OWASP Top Ten 2017 Category — analog for supply-chain integrity) + CWE-915 (Improperly Controlled Modification of Dynamically-Determined Object Attributes — for the option group binding)
- **Location**: `spec.md` §Security Checklist + `plan.md` §Constitution Check §III + `vendor/acrossai-co/main-menu/src/PageRenderer.php` (unchanged by this feature)
- **Spec-Kit task**: TASK-SEC-012-007

**Problem.** Feature 012's MCP tab relies on the vendor `PageRenderer::render()` method to (a) call `settings_fields( 'acrossai-settings' )` which emits the `_wpnonce` + `_wp_http_referer` fields, and (b) route the Save POST through `options.php` which validates via the `option_group` allowlist. If the vendor package is ever compromised at runtime (supply-chain attack) or updated to a version that emits a wrong nonce or wrong `option_group`, Feature 012's toggles would silently fail to save — or worse, save under a different option group where `register_setting()` didn't allowlist them.

**Plan mitigations.** Feature 010's `composer.lock` pin + `git diff --exit-code vendor/` CI gate (per Feature 010 SEC-010-001) provides supply-chain integrity. This feature does not touch vendor. Sibling `acrossai-abilities-manager` also depends on the same vendor package + option group, providing an independent-project sanity check.

**Residual risk.** Inherited from Feature 010 SEC-010-001; no new attack surface introduced by this feature.

**Remediation.** None specific to Feature 012. See Feature 010 SEC-010-001 for the vendor-diff gate.

### SEC-012-008 — Section-description render methods construct HTML from URLs; escape idiom is load-bearing

- **Severity**: INFORMATIONAL
- **CVSS v3.1**: 3.7 (`AV:N/AC:H/PR:H/UI:R/S:U/C:L/I:L/A:N`)
- **OWASP Category**: A02:2025-Cryptographic Failures (loose analog for "missing output escape can leak session-related data to XSS") — could also be A03 Injection
- **CWE**: CWE-311 (Missing Encryption of Sensitive Data — loose analog) + CWE-1284 (Improper Validation of Specified Quantity in Input)
- **Location**: `spec.md` FR-012 + FR-008 + FR-009 + `plan.md` §Concrete decisions on escape idiom
- **Spec-Kit task**: TASK-SEC-012-008

**Problem.** Two of the render methods on `SettingsMenu` produce HTML that contains dynamic URL values:

- `render_npm_section_description()` — displays the frontend CLI auth URL from `FrontendAuth::get_base_url()` inside a `<code>` tag within a `.notice-warning` div.
- `render_claude_connectors_section_description()` — displays THREE OAuth URLs (authorization server metadata, authorize URL, token endpoint) inside `<code>` tags within a `.notice-warning` div.

Spec FR-012 mandates the escape idiom: `printf( wp_kses_post( __( '<code>%s</code>...', ... ) ), esc_html( $val ) )` — the translated format string goes through `wp_kses_post` (to allow the HTML tags), and each `%s` substitution goes through `esc_html` (for plain string values) or `esc_url` (for URL values). This is correct but subtle.

If an implementer at TASK-1 writes `printf( wp_kses_post( __( '<code>%s</code>', ... ) ), $val )` (missing the `esc_html` wrapper on `$val`), the URL bypasses escaping. `FrontendAuth::get_base_url()` returns a WP-computed URL (safe input), but under a compromised WP install or misconfigured `home_url` filter, a URL containing `"><script>` could be constructed and would XSS the admin page.

**Plan mitigations.** Spec FR-012 mandates the idiom. Sibling `wordpress-ai/src/Admin/Settings.php:465-472` shows the exact `printf` + `wp_kses_post` idiom. Plan's TASK-1 code snippets include the correct pattern.

**Residual risk.** The idiom is subtle; a careless refactor could regress it. `esc_url` would be more appropriate than `esc_html` for the URL values specifically.

**Remediation.**
1. At TASK-1 implementation, prefer `esc_url()` over `esc_html()` for URL substitutions in the two render methods — makes intent explicit + slightly more paranoid escape (removes URL-specific control characters).
2. At code-review time, verify EVERY `%s` in a `wp_kses_post`-wrapped format string has a matching `esc_html`/`esc_url`/`esc_attr` wrapper on its substitution argument. Zero exceptions.
3. Optionally, add PHPCS `WordPress.Security.EscapeOutput` sniff at the highest severity level for `admin/Partials/SettingsMenu.php` in `phpcs.xml.dist` — surfaces missing escapes at CI time.

## Confirmed Secure Patterns

The following pre-existing security patterns are explicitly preserved by Feature 012 and are verified against the plan/spec:

1. **SEC-001 atomic-CAS** on `CliAuthLog\Query::redeem_atomic` (Feature 011 FR-006) — DB-layer preservation (Feature 012 FR-028) keeps the method + its `WHERE id = %d AND completed_at IS NULL` predicate intact. Companion pre-flight grep is the merge-gate.
2. **SHA-256 hashed-column widths** (Feature 011 FR-010) — `char(64)` on `auth_code_hash` + `access_token_hash`; `char(43)` on `code_challenge`. Zero schema changes in this feature.
3. **`$wpdb->prepare()` on every DB path** — the rewritten `uninstall.php` LIKE-sweep uses `$wpdb->prepare()` (FR-020); DROP TABLE loop's `phpcs:ignore` is scoped + justified (FR-021 + SEC-012-003).
4. **`manage_options` capability gate** on the shared settings page — inherited from vendor `PageRenderer::render()`; `SettingsMenu`'s render methods do not need to re-check.
5. **Nonce validation** via vendor `settings_fields('acrossai-settings')` — inherited; `options.php` handoff validates.
6. **Sanitize contracts on all 3 options** — `rest_sanitize_boolean` × 2 (FR-004) + custom `sanitize_uninstall_flag` returning `empty($value) ? 0 : 1` (FR-005).
7. **A1 hook centralization** — `SettingsMenu` contains zero `add_action`/`add_filter` in its class body; hooks live in `Main.php::define_admin_hooks()` (FR-013 + FR-014).
8. **A6 leading-`\` FQN / `use` imports** — every cross-namespace reference uses leading-`\` FQN (`\AcrossAI_Main_Menu\SettingsPage`, `\AcrossAI_MCP_Manager\Public\Partials\FrontendAuth`, `\AcrossAI_MCP_Manager\Admin\Partials\SettingsMenu` in Main.php).
9. **A8 preservation of Access Control conditional submenu** — position 4 in Menu.php (guarded by `class_exists('\WPBoilerplate\AccessControl\AccessControlManager')`) survives position-3 CLI Auth Log deletion (FR-025).
10. **Preserve-by-default uninstall gate** — FR-019 places the gate at the TOP of `uninstall.php`; every destructive op after the gate.
11. **Constitution §III consent-surface exception NOT applied** — the MCP tab is admin-only, not a browser-mediated user-on-own-behalf credential-issuing surface. Standard `manage_options` gate is correct (see spec Admin UI Requirements + memory-synthesis §III note).

## Action Plan & Next Steps

### Durable Memory Preservation

Three capture-worthy patterns emerged from this review that are already staged for Feature 012 TASK-7 per spec FR-029:

- **DEC-VENDOR-SETTINGS-TAB-INTEGRATION** — canonical pattern for consuming the vendor tab API (filter, helper, option-group binding, class-shape mirroring).
- **DEC-UNINSTALL-OPT-IN-GATE** — preserve-by-default with opt-in destructive teardown; addresses SEC-012-001.
- **DEC-ADMIN-SURFACE-PRUNE-CLI-AUTH-LOG** — subtractive-edit precedent for `plugin_screen_ids()`; addresses SEC-012-006.

Two additional lessons surfaced during this review that COULD be captured as durable memory but are lower-priority follow-ups:

- **The "silent default change in operator-facing behavior" bug pattern** (from SEC-012-001) — a class of operator-surprise bugs that goes beyond this feature. Consider a future `docs/memory/BUGS.md` entry (e.g., `B16 — Behavioral drift in uninstall/teardown paths silently subverts operator runbooks`).
- **The "hard-require re-evaluation trigger" rule** (from SEC-012-004) — cross-feature memory discipline for demoting hard-requires. Consider adding to `AGENTS.md` Before Commit Checklist under a "Vendor-package integration changes" heading.

**Deferring `/speckit-memory-md-capture` invocation** to Feature 012's TASK-7 for the primary three DECs, consistent with prior features' convention. The two follow-up lessons can be surfaced during TASK-7 as optional additions.

### Remediation Planning

No CRITICAL or HIGH findings. Three LOW findings (SEC-012-001, -002, -003) recommend concrete gate additions at `/speckit-tasks` T4/T5/T6. Five INFORMATIONAL findings are advisory + set architectural precedent.

**Recommended next command**: `/speckit-tasks` — the task decomposition should encode:
- **T4 DoD**: consider adding the optional 4th test method for uninstall preserve-by-default per SEC-012-005.
- **T5 DoD**: reviewer callout for `phpcs:ignore` line-scope verification per SEC-012-003; consider migrating DROP TABLE loop to `%i` placeholder if implementer time allows.
- **T6 DoD**: automate the companion CliAuthLog DB-layer grep per SEC-012-002 (via CI workflow or PHPUnit reflection check).
- **T7 DoD**: three DEC captures + INDEX/DECISIONS/WORKLOG coherence + consider surfacing the two follow-up lessons (SEC-012-001 behavioral-drift bug pattern; SEC-012-004 hard-require re-evaluation rule).

If any of these findings escalate during implementation, run `/speckit-security-review-followup` to open remediation tasks.

---

## Memory Hub INDEX.md Row

```text
| docs/security-reviews/2026-07-03-012-mcp-settings-tab-plan.md | plan | 2026-07-03 | LOW | C:0 H:0 M:0 L:3 | A02,A05,A08,A09 |
```
