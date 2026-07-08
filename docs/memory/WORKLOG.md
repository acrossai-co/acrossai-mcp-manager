# Worklog

Use concise high-value entries only.
This is not a changelog. Do not record routine releases, version bumps, or implementation summaries.

## Template

### YYYY-MM-DD - Summary

- why this is durable
- what future mistake it prevents
- evidence
- where future contributors should look

## Example

### 2026-03-15 - Pagination cursor must be opaque to clients

- **Why durable**: three features so far have tried to expose raw database offsets as pagination cursors, each time creating breaking changes when the underlying query changes
- **Future mistake prevented**: next time a feature adds pagination, the implementer will know to use opaque cursors from the start
- **Evidence**: specs 018, 024, and 031 all required pagination rework; see DECISIONS.md entry on API pagination
- **Where to look**: `src/api/pagination.ts`, `docs/memory/DECISIONS.md`

## Counter-Example (do not write entries like this)

> ### 2026-03-15 - Updated pagination
>
> - Changed pagination to use cursors
> - Deployed to staging

This is a changelog entry, not a durable lesson. It records what happened, not what was learned.

### 2026-07-07 - Per-server ability selection (F017) — storage-vs-enforcement decoupling caught mid-flow by the plan-review security gate

- **Why durable**: F017 shipped a BerlinDB table storing per-`(server, ability)` exposure overrides, an interactive `@wordpress/dataviews` React tab, extensibility filters — and initially, **nothing that made those overrides bite at the MCP tool-call boundary**. The plan-phase `/speckit-security-review-plan` surfaced this as SEC-001 HIGH (CWE-863 Incorrect Authorization). Storage-vs-enforcement decoupling is a durable failure mode: whenever a feature adds admin storage that MUST also be consulted by a runtime enforcement site, the wiring is a separate design decision that the storage-side task list will silently omit. Codifying "always trace stored decisions back to their enforcement site" prevents the class.
- **Future mistake prevented**: A future feature that adds new admin-writable state (per-user quotas, per-endpoint rate limits, per-thing X) will silently ship UI + REST + tests — but no enforcement — unless the plan phase explicitly names the enforcement site and adds a task for wiring the storage read into it. The plan-review security phase catches this, but only if the reviewer looks for the enforcement gap. Making the check a durable checklist item catches it earlier.
- **Evidence**: SEC-001 finding in `docs/security-reviews/2026-07-07-017-per-server-ability-selection-plan.md`. Resolved via `/speckit-clarify` Q4 → FR-030 → TASK-10 in the same session — added `Includes\MCP\AbilityExposureGate` wired on `mcp_adapter_pre_tool_call` at priority 20 (F015 = 10), so F017's hidden verdict runs later and supersedes any F015 "allow." Never overrides an F015 deny.
- **Where to look**: `Includes\MCP\AbilityExposureGate::gate_tool_call_by_exposure()` for the enforcement-side pattern. `Includes\REST\AbilitiesController::build_row()` for the storage-side READ. `Includes\Database\MCPServerAbility\ExposureResolver` is the shared truth. `DEC-ABILITY-OVERRIDE-RESOLUTION` codifies the resolver contract; every future storage-writes-plus-enforcement-reads pattern MUST route through a single resolver — do not duplicate the fallback rule.

### 2026-07-07 - Fresh-install-only retirement pattern (F016 Claude Connectors)

- **Why durable**: Retirement features default to shipping self-healing schema migration code (idempotent `DROP TABLE` + `ALTER TABLE` in Activator, `column_exists`-gated fallback helpers, `$version` bumps triggering BerlinDB diff runs). F016's initial plan followed that pattern. The 2026-07-07 operator-directive scope reduction ("we are not looking for backward compatibility") eliminated ALL in-plugin migration code and delegated it to a manual SQL recipe published in README.txt §Unreleased. Net effect: smaller diff (~80 fewer LOC of migration helpers), zero test surface for migration edge cases, no Activator risk of running destructive SQL by mistake, no coordinated-deployment `$version` bump. This is the canonical shape for future retirements when operator attestation covers no-live-data OR the operator explicitly manages migration.
- **Future mistake prevented**: A future retirement feature that reflexively adds self-healing migration to Activator will ship more code, more test surface, more moving parts, and more Activator-destructive-SQL risk than needed. The reflex is wrong when the operator has explicit control over deployment. Verify attestation FIRST; if attestation covers no-live-data, apply this pattern.
- **Evidence**: Initial plan (`specs/016-remove-claude-connectors/plan.md` pre-2026-07-07 revision) included `ConnectorColumnMigration.php` optional helper, `DROP TABLE IF EXISTS` block in Activator, `$version 0.0.1 → 0.0.2` bump. All removed 2026-07-07 per user directive. Post-implementation grep audit (T031): 0 hits from baseline 370. Security review confirmed the retirement retroactively closed an S3 constitution gap (plaintext `claude_connector_client_secret` in `varchar(255)`) without shipping migration code — the operator's `UPDATE ... SET ''` step in the README recipe forces InnoDB page rewrite before column drop (SEC-016-001 defense-in-depth).
- **Where to look**: Pattern reference: `specs/016-remove-claude-connectors/research.md` Decision 1 + Decision 5. Operator recipe: `README.txt` §Unreleased. Contrast: F011 (2026-07-02) which SHIPPED the phantom-version guard because that migration WAS a runtime operation, not an operator recipe. Canonical decision: `DECISIONS.md::D21`.

### 2026-07-04 - Post-implementation drift audit: run /speckit-analyze AFTER /speckit-implement, not only before

- **Why durable**: When mid-implementation clarifications supersede the initial spec (F015's Q4 superseded Q1 SAFE_CAPABILITIES; user directed a mid-session `TABLE_SLUG` rename `mcp_manager` → `mcp`; user directed a pivot from hand-rolled PHP form to vendor React `<AccessControl>` component; T030 dead-code follow-up), the shipped code drifts from spec + tasks + memory-synthesis + security-constraints + architecture-violations in ways the pre-implement `/speckit-analyze` cannot catch. Running `/speckit-analyze` AFTER `/speckit-implement` (as a spec-hygiene checkpoint) catches: stale FR text still describing withdrawn designs, table-name/slug references that missed the rename sweep, dead-code follow-up tasks not yet added, historical clarification records that need preservation vs. amendment, docs-vs-shipping drift in per-feature `security-constraints.md` and `architecture-violations.md`.
- **Future mistake prevented**: A future feature that ships with mid-session clarifications supersession or user-directed pivots will have its spec docs drift out of sync with the shipped code — reviewers reading the archive later will believe the FR text (e.g. "form emits `<form method="post">`") when the code ships something completely different (a vendor React mount div). Running the post-implement audit catches this before the docs get committed, preserving the archive's trustworthiness.
- **Evidence**: F015 post-implement `/speckit-analyze` (this session) surfaced 4 CRITICAL/HIGH drift categories — F1 table slug (~55 stale `mcp_manager` occurrences across 6 spec files), F2 vendor React pivot (FR-009/FR-010/T011/T012 all still described the withdrawn PHP form), F3 SAFE_CAPABILITIES cascade (FR-025 marked SUPERSEDED but 5 downstream references still active-context), F13 uninstall wording gap. All 4 remediated in the same session; second-pass `/speckit-analyze` returned zero CRITICAL/HIGH findings.
- **Where to look**: `.claude/skills/speckit-analyze/` for the audit prompt; the F015 spec + tasks + memory-synthesis + security-constraints + architecture-violations files as the canonical example of "what drift looks like when it's caught and fixed" (see the "amended 2026-07-04 —" markers throughout). For workflow: add `/speckit-analyze` after `/speckit-architecture-guard-governed-implement` in any feature that had Clarifications sessions or user-directed pivots.

### 2026-07-03 - Vendor-owned shared Settings page: register_setting() option_group MUST be the shared slug, not the per-tab page slug

- **Why durable**: The vendor-owned `PageRenderer::render()` emits ONE `settings_fields('acrossai-settings')` nonce for the whole shared page — every consumer plugin's `register_setting()` MUST target that shared option group or Save silently no-ops with no operator-visible error. Getting this wrong looks like a working form (nonce validates, `options.php` accepts the POST) until the reload shows the toggle reverted to its prior value. Feature 012 is the first cross-plugin consumer to formalize this contract; codifying it here prevents every subsequent consumer plugin from rediscovering the trap.
- **Future mistake prevented**: A future AcrossAI plugin developer using the standard WordPress Settings API `register_setting( '<page-slug>', ... )` reflex — which is correct for a plugin-owned single-page Settings screen — silently breaks Save on the shared vendor page. Reviewers looking at just the tab code cannot spot the bug without knowing the vendor contract; the failure is invisible in phpstan, phpcs, and even PHPUnit runs that stub the option layer.
- **Evidence**: Feature 012 spec.md CONSTRAINTS ("Do not change the 'acrossai-settings' option group"); Feature 012 security review SEC-012 Trust Boundaries table ("Vendor Save routing → wrong option group"); Sibling `acrossai-abilities-manager/admin/Partials/SettingsMenu.php:111-118` — matching pattern; DEC-VENDOR-SETTINGS-TAB-INTEGRATION rule 3.
- **Where to look**: `admin/Partials/SettingsMenu.php::register_settings()` for the canonical shape. Any future AcrossAI plugin that adds a tab MUST match this exact shape verbatim — the `option_group` argument is the load-bearing invariant, not the `page_slug`.

### 2026-07-02 - BerlinDB Table subclasses must override maybe_upgrade() with a phantom-version guard

- **Why durable**: The phantom-version guard on every BerlinDB-backed Table subclass is a canonical safety belt against the "version option stamped but physical table missing" edge case. Costs one method override; prevents an entire class of hard-to-diagnose "table doesn't exist" activation bugs.
- **Future mistake prevented**: A future BerlinDB-backed table shipped without the guard could silently short-circuit `maybe_upgrade()` on any install where a prior activation failed mid-DDL. The bug is invisible until users complain about missing rows/features.
- **Evidence**: The observed `wp_acrossai_mcp_servers doesn't exist` symptom that originally motivated Feature 011 on the developer's local install (2026-07-02).
- **Where to look**: The four subclass file paths — `includes/Database/{MCPServer,CliAuthLog,OAuthToken,OAuthAudit}/Table.php` — each contains a `public function maybe_upgrade(): void` override. Canonical sibling reference at `/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/plugins/acrossai-abilities-manager/includes/Modules/Abilities/Database/AcrossAI_Abilities_Table.php:96-101`.
