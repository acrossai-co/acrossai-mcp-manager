# Feature 069 Architecture Migration Plan

## Current state

Feature 069 (MCP Quick Setup Wizard) is a **net-new admin surface**, not a migration of existing code. The 63 tasks in `tasks.md` are all additive per FR-029/030. Zero non-additive edits under `admin/Partials/ServerTabs/`, `includes/REST/` (except the new controller), or `includes/Database/`.

### Refactor scope surfaced by architecture review

Two architectural findings surfaced during the tasks-phase review that require refactor tasks (not full migrations):

1. **REF-001** — Input-boundary contract on `MCPServerFieldSanitizer` needs an explicit key whitelist.
2. **REF-002** — Cross-surface component extraction (AC editor: tab → shared) needs a security-continuity assertion in the same PR.

Both are single-file additions or amendments to existing tasks — **no phased migration needed**. Documented here for governance completeness per `/speckit-architecture-guard-refactor-generator` workflow.

## Target state

- `MCPServerFieldSanitizer` filters incoming payload against a hard-coded whitelist of 6 known keys BEFORE per-field sanitization runs. Additional keys silently dropped. Enforced by PHPUnit fixture including a mass-assignment negative test.
- Post-T032 (AC editor extraction to shared component), a security-continuity check asserts every security invariant present in the pre-refactor tab-mounted version is preserved in the extracted `<AccessControlEditor>`. Enforced by pre/post grep comparison + a manual QA checklist item.

## Migration phases

**Not applicable** — both refactors are single-file additions:
- REF-001: amend T012 + T013 in `tasks.md` (whitelist enforcement + negative test).
- REF-002: add new task alongside T032 (grep-based continuity verification).

No phased rollout. No coexistence period. No rollback plan (no shipping code changes yet — this lands during Phase 3 implementation).

## Coexistence strategy

**Not applicable** — both refactors are wholly contained in the new-code path and its own test surface. No old/new pattern coexistence.

## Rollback plan

**Not applicable pre-implementation.** If a post-implementation audit reveals the whitelist or continuity assertions were dropped, the fix is a subsequent PR extending `MCPServerFieldSanitizer` or `AccessControlEditor` — not a rollback.

## Success criteria

- [ ] `tasks.md` amended per REF-001 (T012/T013 include whitelist enforcement + mass-assignment negative test).
- [ ] `tasks.md` amended per REF-002 (new task or amendment to T032 with pre/post grep continuity check).
- [ ] Post-implementation `MCPServerFieldSanitizer::sanitize()` filters against a hard-coded 6-key whitelist.
- [ ] Post-implementation `AccessControlEditor` preserves every `wp_create_nonce` / `nonce` / capability / render-safety invariant present in the pre-refactor `src/js/access-control.js`.
