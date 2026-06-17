# Governed Planning Summary — Phase 2: Admin UI

**Date**: 2026-06-17 | **Branch**: `002-admin-ui`

---

## Memory Context

- **Status**: Synthesized (markdown-only fallback; `flash-mem` not installed)
- **Synthesis file**: `specs/002-admin-ui/memory-synthesis.md`
- **Key Constraints applied**:
  - **A1** — All hook registration in `includes/Main.php` via Loader (`define_admin_hooks()`)
  - **A2** — Every feature class uses singleton `instance()` + private `__construct()`
  - **A3** — All admin UI in `admin/Partials/` with namespace `AcrossAI_MCP_Manager\Admin\Partials`
  - **A6** — `Includes\*` files MUST use `use` imports or leading-`\` FQN for sub-namespace refs (bare relative names silently fail — bug B1)
  - **DEV1** — Pre-approved `WP_List_Table` + tabbed-form exception for the MCP Manager parent menu (extended in spec to cover the CLI Auth Log submenu)
  - **D5** — PHPCS Phase 1 baseline exceptions preserved
  - **D8** — AccessControl wiring uses vendor FQN `\WPBoilerplate\AccessControl\AccessControlManager`
  - **S1 / S5 / S6** — Nonces on every mutator; `esc_url(admin_url())` mandatory; private singleton ctor mandatory
  - **B1 / B5 / B6** — Anti-patterns explicitly guarded against

---

## Security Review

- **Status**: Reviewed inline (extension `security-review` available; `/speckit-security-review-plan` performed inline by the orchestrator)
- **Constraints document**: `specs/002-admin-ui/security-constraints.md`
- **Constraints Found**:
  - Trust boundary table established (anon → wp-admin, non-admin → admin actions, cross-origin → state change, DB query input, HTML output, plugin → vendor)
  - Data isolation correct: MCP Servers per-site; Application Passwords per-server with hashed storage; notice dismissal per-user via user_meta; Claude Connector secret per-server-row (plaintext at rest — Finding F3)
  - Async path (admin-ajax dismissal) is idempotent and fire-and-forget — no race window
- **Warnings**:
  - **F1 — slug sanitiser scope** (Advisory): `sanitize_text_field()` rather than `sanitize_key()` — preserved per 1:1 port; flag as follow-up
  - **F3 — Claude Connector Secret plaintext at rest** (Advisory): preserved per 1:1 port; flag as follow-up phase
  - **F4 — `esc_url(admin_url())` reminder** (Advisory with mandatory verification): every `admin_url(...)` call site MUST be wrapped; tasks phase MUST add grep gate
  - **F5 — Prerequisite Query classes absent** (P0 dependency): already enforced as task T000 in plan
- **No Security-Architecture Conflict detected.**

---

## Architecture Review

Performed inline against `.specify/memory/constitution.md` v1.0.0 +
`docs/memory/INDEX.md` selected entries.

### Violations

**None blocking.** Every Constitution Core Principle and selected memory
entry passes:

| Principle / Constraint | Status | Note |
|---|---|---|
| I. Modular Architecture | ✅ | Six self-contained partials |
| II. WordPress Standards | ✅ | PHPCS WPCS strict, PHPStan L8, ESLint mandated in DoD |
| III. Security First | ✅ | Nonce + cap + sanitise + escape enforced per FR; see F3 follow-up |
| IV. User-Centric Design | ✅ (DEV1) | WP_List_Table + tabbed form covered by pre-approved exception |
| V. Extensibility Without Core Modification | ✅ | All optional integrations `class_exists()`-guarded |
| VI. Reusability & DRY | ✅ | No new utility; 1:1 port preserves existing source-internal duplication as a future-phase cleanup target |
| VII. Definition of Done | ✅ | Spec DoD maps 1:1 to constitution gates |
| Boot Flow Rule | ✅ | Loader-wiring contract pins named-singleton-variable form |
| Admin Partials Rule | ✅ | All six files in `admin/Partials/` |
| PHP Namespace Rule | ✅ | Namespaces mirror directory paths |
| Module Contract | ✅ | Private ctor; vendor `MCPServer\Query` instantiated per-query (library class, not feature class — permitted) |
| A1 — Hook registration | ✅ | Loader-wiring contract is exhaustive |
| A2 — Singleton pattern | ✅ | Q1 clarification reconciled to A2 |
| A3 — admin/Partials/ namespace | ✅ | FR-024 |
| A4 — DataForm / DataViews | ✅ (DEV1) | Exception applies; spec documents extension to CLI Auth Log submenu |
| A6 — `use` imports inside `Includes\*` | ⚠️ Soft | Plan's loader-wiring.md uses leading-`\` FQN; both forms satisfy A6; implementer may switch to `use` |
| B1 — namespace silent-fail | ✅ | Leading-`\` FQN in contract is safe |
| B5 — public ctor double-hook | ✅ | Private ctor enforced |
| B6 — `admin_url()` XSS | ⚠️ Soft | F4 reminder — implementation gate via grep |

### Consistency Risks

- **DEV1 reach interpretation** (A4 soft conflict from memory-synthesis):
  Phase 2 extends DEV1 to cover the CLI Auth Log submenu. The
  constitution literal says "MCP Manager parent menu only". The spec
  interprets this as "parent menu and its submenus that follow the same
  WP_List_Table pattern". **Risk**: a future architecture-guard pass
  could challenge this. **Mitigation**: spec.md §Admin UI Requirements
  documents the interpretation with reasoning. Non-blocking.

- **Loader-wiring style choice** (A6 soft): both `use` imports and
  leading-`\` FQN satisfy the rule. Implementation may pick either —
  preferring `use` imports if the file has ≥3 sub-namespace references
  to reduce noise.

---

## Recommended Actions

1. **Continue to `/speckit-tasks`** — generate the implementation task
   list. The first task (T000) MUST verify the prerequisite BerlinDB
   Query classes exist in `includes/Database/`; implementation cannot
   begin if T000 fails.

2. **Tasks phase MUST add a verification gate** for F4:
   ```bash
   grep -rn 'admin_url' admin/ | grep -v 'esc_url\|esc_attr'
   # Expected: empty
   ```
   This catches B6/S5 violations before merge.

3. **No `/speckit-architecture-guard-refactor-generator` needed** —
   zero architectural violations detected.

4. **Defer follow-ups**:
   - F1 (slug sanitiser scope) — clean-up phase
   - F3 (Claude Connector Secret at-rest encryption) — when OAuth phase
     (Phase 6) lands, revisit Constitution §III to cover outbound client
     secrets explicitly

5. **Durable Memory Preservation** — Reviewed for new patterns:
   - Q1 reconciliation = A2 applied → not new
   - BerlinDB method-name map (research R1) = one-off translation, not
     a durable pattern
   - Admin-ajax dismissal = standard WP pattern, not project-specific
   - DEV1 reach interpretation = spec-level interpretation, eligible
     but minor

   **Decision**: **Do not auto-trigger `/speckit-memory-md-capture`.**
   The only candidate (DEV1 reach interpretation) is documented in
   spec.md and would add noise to the memory index without
   distinguishing a separable architectural decision. If a later phase
   genuinely needs to extend a DEV exception to a non-parent screen
   under a different exception clause, that would warrant a new
   memory entry — but Phase 2 stays inside the spirit of DEV1.

---

## Status

**Phase 2 plan is governance-cleared for `/speckit-tasks`.**
Cross-phase blocker: prerequisite Query classes must ship before
implementation can complete (T000 gate).
