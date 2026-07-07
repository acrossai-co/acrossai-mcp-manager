# Security Constraints — Feature 016

**Source**: `docs/security-reviews/2026-07-07-016-remove-claude-connectors-plan.md`
**Overall risk**: LOW (0 CRITICAL / 0 HIGH / 0 MEDIUM / 1 LOW / 3 INFORMATIONAL)
**Net effect**: Attack surface reduction.

## Trust boundaries changed by Feature 016

| Boundary | Pre-016 | Post-016 |
|---|---|---|
| `POST /wp-json/acrossai-mcp/v1/token` REST route | Open (S7 exception) | RETIRED — returns 404 |
| `determine_current_user` bearer-token resolver | Registered (priority 20) | RETIRED — bearer headers do not elevate |
| OAuth `.well-known/*` discovery endpoints | Public, no auth | RETIRED — 404 |
| OAuth consent form (`?acrossai_mcp_oauth=authorize`) | Consent-surface exception | RETIRED — 404 |
| Daily cron `acrossai_mcp_oauth_cleanup` | Scheduled | UNSCHEDULED |
| `wp_acrossai_mcp_oauth_tokens` (hashed tokens) | Present | DROPPED |
| `wp_acrossai_mcp_oauth_audit` (audit rows) | Present | DROPPED |

## Constraints Architecture Guard must enforce

1. **`DEC-UNINSTALL-OPT-IN-GATE` compliance** — every `DROP TABLE` and `delete_option` in `uninstall.php` MUST live AFTER the `acrossai_mcp_uninstall_delete_data !== 1` short-circuit. Plan Decision 2 honors this; verify in code review.
2. **BerlinDB phantom-version guard preservation** — MCPServer `Table::maybe_upgrade()` override MUST remain intact during the `$version` bump. Any accidental removal reopens the F011 activation bug.
3. **CLI auth stack isolation** — `FrontendAuth`, `CliController`, `wp_acrossai_mcp_cli_auth_logs`, `includes/Database/CliAuthLog/`, `public/Partials/FrontendAuth.php`, and the `acrossai-mcp-frontend` stylesheet handle MUST NOT be touched. Any grep hit crossing this boundary during implementation is a defect.
4. **Consent-surface exception preserved** — Constitution §III Consent-surface exception (added 2026-06-30) remains valid for `FrontendAuth`; do not remove or supersede the exception itself, only the retired OAuth-consent-form consumer.
5. **B15 grep hygiene** — FR-015 grep regex MUST match BOTH `use OAuth\Storage;` (bare) AND `\AcrossAI_MCP_Manager\Includes\OAuth\Storage` (leading-`\` FQN) forms. Quickstart's B15 seed-then-remove ritual proves the regex works before treating zero-hits as PASS.

## Follow-ups (non-blocking, fold into task decomposition)

- **SEC-001 (LOW, CWE-212) — SUPERSEDED by user directive 2026-07-07**: Original finding recommended adding a pre-DROP `UPDATE ... SET '' ` overwrite to force InnoDB page rewrite before the plugin dropped the plaintext-secret columns. Under the revised scope, the PLUGIN does not drop columns — the operator does that manually. SEC-001's finding transfers to the operator's manual SQL recipe. Recommended addition to spec §User Story 2 recipe: prepend `UPDATE wp_acrossai_mcp_servers SET claude_connector_client_secret = '', claude_connector_redirect_uri = '';` before the `ALTER TABLE ... DROP COLUMN`. Documentation-only follow-up (README.txt Unreleased); no plugin code change.
- **SEC-002 (INFO)**: Advise operators in README.txt Unreleased to revoke active claude.ai connector tokens and export audit rows BEFORE running the manual retirement SQL. Fold into TASK-10.
- **SEC-003 (INFO)**: Document in README.txt Unreleased that `Authorization: Bearer` headers no longer elevate users; direct integrators to WP App Passwords / CLI auth. Fold into TASK-10.
- **SEC-004 (INFO)**: Memory-hygiene annotations for S7, DEC-CLIENT-RENDERER-PUBLIC-API, A13, Constitution Principle I Rationale + Directory Layout. Post-implementation via `/speckit-memory-md-capture-from-diff`.
- **SEC-005 (INFO — from tasks review 2026-07-07)**: T030's manual smoke omits 3 runtime 404 assertions (OAuth well-known URLs + retired token endpoint) that SC-007 and quickstart.md §US2 both prescribe. Amend T030 to add a step (f) enumerating the 3 curl assertions before merge.
- **SEC-006 (INFO — from tasks review 2026-07-07)**: T037 (post-merge memory hygiene) has no forcing function. Either (a) promote to a same-PR checklist item so annotations land in the Feature 016 PR, or (b) open a linked follow-up issue "Feature 016 memory-hygiene follow-up" and reference it from the merge commit message.

## Confirmed secure patterns already in the plan

- Idempotent Activator operations (safe across repeat activations).
- Uninstall opt-in gate preserved with correct DROP placement.
- BerlinDB canonical schema-management path (not bespoke migration).
- Grep-audit safety net for silent-namespace-fail (B1) with B15 verification guard.
- No new authentication surface, no new authorization boundary, no new data collection, no new async operation.

## Handoff

Ready for Step 5 of `/speckit-architecture-guard-governed-plan` (violation detection). No hard security-architecture conflicts; Architecture Guard should focus on hook-registration integrity (A1), namespace/FQN correctness (A6/B1), request-time BerlinDB Table boot removal (DEC-BERLINDB-TABLE-REQUEST-BOOT), and the `Registry`/`ClientRendererController` surface-shrink patterns.
