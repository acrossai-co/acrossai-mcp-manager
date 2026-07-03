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
