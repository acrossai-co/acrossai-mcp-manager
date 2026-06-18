# Governed Planning Summary — Phase 4: MCP Client Classes

**Date**: 2026-06-17 | **Branch**: `004-mcp-clients`

---

## Memory Context

- **Status**: Synthesized (markdown-only fallback; `flash-mem` not installed). Reused from `specs/004-mcp-clients/memory-synthesis.md` (created by the prior `/speckit-memory-md-plan-with-memory` turn).
- **Key Constraints applied**:
  - **A1** (Loader-only hooks) — fully honored (FR-008 zero hooks)
  - **A2** (Singleton + private ctor) — **soft exemption** for pure service classes; precedent set by A10 for `WP_List_Table`; **A11 candidate** queued for post-implementation capture
  - **A6** (`use` imports inside `Includes\*`) — honored
  - **D10** (Minimal-port deferral pattern) — Phase 4 IS the deferred port that D10 captured; closes the loop
  - **B1** (Namespace silent-fail) — mitigated by `use` imports
  - **S3** (OAuth token hashed storage) — honored at the right boundary (this module emits transit material, not storage)

---

## Security Review

- **Status**: Reviewed (extension `security-review` available; performed inline)
- **Constraints document**: `specs/004-mcp-clients/security-constraints.md`
- **Constraints Found**: Trust boundary table established. Data isolation verified — no class properties hold tokens, all I/O is function-scoped. Async context: none (no REST, no ajax, no cron).
- **Findings**: 4 total — F1 (token plaintext in snippet — by design), F2 (consumer escape required — Phase 2 RT-3 amendment concern), F3 (placeholder English-only — accepted trade-off), F4 (malformed class fatal — accepted per spec). **Zero Critical / High / Medium.** Overall risk: **LOW.**
- **No Security-Architecture Conflict detected.**

---

## Architecture Review

Performed inline against `.specify/memory/constitution.md` v1.0.0 +
`docs/memory/INDEX.md` selected entries.

### Violations (post-resolution of V1/V2/V3 from governance-plan args)

**None blocking.** The three governance-plan conflicts raised at the
start of this turn were resolved:

| ID | Conflict | Resolution chosen | Status |
|---|---|---|---|
| **V1** | Constructor injection for `Settings` (Constitution A2 + Module Contract item 2) | `singleton` — Settings stays no-arg; consumer resolves MCPClients via `AbstractMCPClient::get_all_clients()` at use-site (parallel to Phase 2 R4 vendor pattern) | ✅ Resolved |
| **V2** | "Migration" framing vs spec's clarified 3-method interface | `redesign` — plan describes as interface-redesign port; source consulted for AI-tool envelope shapes only | ✅ Resolved |
| **V3** | `AbstractMCPClient::get_all_clients()` static factory vs spec FR-010 file-scan | `both` — public API is `get_all_clients()`; internal mechanism is the file-scan from original FR-010. Spec FR-010 updated. | ✅ Resolved (spec amended) |

### Constitution alignment table

| Principle / Constraint | Status |
|---|---|
| I. Modular Architecture | ✅ |
| II. WordPress Standards | ✅ |
| III. Security First | ✅ (no surface in this module; consumer responsibility) |
| IV. User-Centric Design | ✅ (no UI added) |
| V. Extensibility Without Core Modification | ✅ (SC-002 preserved) |
| VI. Reusability & DRY | ✅ (3 shared helpers: build_server_url, derive_server_key, safe_token, redact_token — all DRY) |
| VII. Definition of Done | ✅ (PHPUnit harness flagged as P0 prereq) |
| A1 (Loader-only hooks) | ✅ |
| A2 (Singleton pattern) | ⚠️ Soft exemption — see A11 candidate below |
| A6 (`use` imports in `Includes\*`) | ✅ |
| A10 (`WP_List_Table` singleton exemption precedent) | ✅ Used as precedent justification for A2 exemption |
| B1 (Namespace silent-fail) | ✅ Mitigated by `use` imports |

### Consistency Risks

- **R-1 (soft)**: A2 vs FR-009 exemption rationale lives in spec.md +
  plan.md Constitution Check + memory-synthesis.md Conflict Warnings —
  three places. After implementation, the A11 memory capture (proposed
  below) should consolidate the rationale into one durable place.
- **R-2 (P0 dependency)**: PHPUnit harness must exist before DoD gates
  can pass (same model Phase 2 had for the BerlinDB Query classes). Not
  blocking planning; will appear as T000 in the tasks phase.

---

## Recommended Actions

1. **Continue to `/speckit-tasks`** — generate the implementation task
   list. Expected scope: ~25 tasks (8 implementation files + ~14 test
   classes + 3 polish gates).

2. **Tasks phase MUST include T000 gate**: verify the PHPUnit harness
   exists. If absent, surface as a cross-phase blocker (do NOT bundle
   the harness setup into this phase — it's a shared infrastructure
   investment).

3. **No `/speckit-architecture-guard-refactor-generator` needed** —
   zero architectural violations after V1/V2/V3 resolution.

4. **No carried-forward security follow-ups** — Phase 2's SEC-001 is
   unrelated; Phase 4 has zero new findings of any severity.

5. **Durable Memory Preservation candidate**: Post-implementation
   (after architecture-guard validation of the running code), propose
   capture:

   - **A11 — Pure service classes in `includes/MCPClients/` (and
     equivalent stateless-value-producer modules) are exempted from
     the singleton rule (A2)**. Rationale: classes that hold no
     instance state and produce a deterministic output from their
     inputs have nothing to gain from sharing a single instance. A
     singleton would add ceremony (`$_instance`, `instance()`, private
     ctor) for zero benefit and create a "must this be unit-tested with
     the singleton state reset?" question that doesn't exist when each
     test instantiates fresh. Parallel to A10 (`WP_List_Table`
     subclasses), different rationale, same outcome: not every class is
     a singleton.

   **Do not auto-trigger `/speckit-memory-md-capture` at planning
   stage.** The exemption is currently spec-level (FR-009) + plan-level
   (Constitution Check). Elevating to durable memory should happen
   after implementation confirms the exemption works in practice. If
   you want to capture now, say so explicitly.

---

## Status

**Phase 4 plan is governance-cleared for `/speckit-tasks`.** Cross-phase
blocker: PHPUnit harness must exist before T000 passes.
