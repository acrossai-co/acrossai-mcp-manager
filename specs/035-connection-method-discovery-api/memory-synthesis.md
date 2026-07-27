# Memory Synthesis

## Current Scope

F035 — Add `ConnectionMethodRegistry` under `public/Discovery/` (namespace `AcrossAI_MCP_Manager\Public\Discovery`) exposing NPM methods + MCP clients + AI connectors as unified plain-associative-array DTOs. Two new filters (`acrossai_mcp_npm_methods`, `acrossai_mcp_connection_methods`). Delegates to F034 (`AbstractMCPClient::get_all_registered_clients()`) and F021 (`ConnectorProfileRegistry::get_profiles()`); never re-fires their filters. Adds `discovery` PHPUnit suite + CI step. Light touch on `NpmClientBlock` (new static `get_default_npm_method()` helper only).

## Relevant Decisions

- **DEC-CLIENT-RENDERER-PUBLIC-API (F013, annotated F016)** — sets the `public/` layer contract + `@experimental` freeze-at-1.0.0 policy. F035 spec cites this decision explicitly for FR-001. (Reason Included: F035 IS a new `public/` layer entry; Status: Active; Source: DECISIONS.md)
- **D35 / DEC-F034-SELF-CONTAINED-SUBSYSTEM-CONTRACT (F034)** — abstract-base-owns-metadata + one canonical static enumeration; renderers + admin partials + other consumers MUST delegate, never re-implement. F035 IS a canonical consumer; FR-010 (`get_clients()` delegates to `get_all_registered_clients()`) + FR-017 (never re-fire underlying filters) enforce this. (Reason Included: F035's core delegation principle inherits directly from D35; Status: Active; Source: DECISIONS.md)
- **D22 (F021 Phase 10 / F024)** — inline-shipped follow-up features MUST fold into parent spec as concrete task IDs, not `See F###` pointers. Applies to F035's `/speckit-tasks` output: any related work (BuddyBoss add-on contract negotiation, etc.) must be enumerated as T-IDs, not referenced as "see F036 brief". (Reason Included: governance meta for tasks.md hygiene; Status: Active; Source: DECISIONS.md)

## Active Architecture Constraints

- **A1** — all hook registration in `Main.php` via `define_admin_hooks()` / `define_public_hooks()`. F035 DEFINES two new filters (fires them inside the class) but does NOT register any `add_filter` — so `Main.php` needs no changes. Reviewer MUST verify `grep -rn "add_filter\|add_action" public/Discovery/` returns zero hits. (Reason Included: F035 could accidentally grow into A1 territory if a "convenience helper" is later added; Source: ARCHITECTURE.md)
- **A2** — every feature class uses singleton `instance()` pattern. F035 FR-002 mandates this. (Reason Included: F035 is stateful (memoization per FR-005) so A11 exemption does NOT apply; Source: ARCHITECTURE.md)
- **A6** — bare relative namespace names silently fail inside `AcrossAI_MCP_Manager\...` — MUST use `use` imports OR leading-`\` FQN. F035 will reference `AcrossAI_MCP_Manager\Includes\MCPClients\AbstractMCPClient` and `AcrossAI_MCP_Manager\Includes\Connectors\ConnectorProfileRegistry` from a `Public\Discovery` namespace — one cross-namespace hop. Explicit `use` imports required. (Reason Included: cross-namespace reference is exactly the B1 failure mode; Source: ARCHITECTURE.md)
- **A11** — pure service class exemption applies to STATELESS classes. F035 is NOT pure (memoization state per FR-005) so A11 does NOT grant a singleton exemption. FR-002 explicitly requires A2 singleton form. (Reason Included: prevents "just make it static, it's basically stateless" drift; Source: ARCHITECTURE.md)
- **A18 (F034)** — WP function stubs in pure-PHP suites permitted under 6 constraints, signals refactor-back-to-pure OR move-to-bootstrap-wp when stub count exceeds ~10. F035 spec Assumptions section cites A18 as the WHY for using `tests/bootstrap-wp.php` (delegates transitively into ConnectorProfileRegistry + NpmClientBlock reading `home_url()`/`get_option()` — would blow past the stub ceiling). (Reason Included: F035 spec explicitly cites A18 as its test-bootstrap-choice rationale; Source: ARCHITECTURE.md)

## Accepted Deviations

None applicable. F035 does not touch admin UI (no A4/DEV1/DEV5 concern), does not touch OAuth token handling, does not add REST routes.

## Relevant Security Constraints

None from INDEX §Security Constraints apply directly (no forms, no REST routes, no DB queries, no plaintext-secret path, no `admin_url()` emit). The security-adjacent semantics live at the filter-fallback layer (Q2 + Q3 clarifications) and are governed by the SEC-013-008 pattern (silent-drop + `_doing_it_wrong` under WP_DEBUG) that F034 established for `acrossai_mcp_client_classes` — F035 replicates identically for both new filters.

## Related Historical Lessons

- **B32 (F026 v3)** — filter defaults that gate security or per-context enforcement MUST be the canonical resolver's output, NEVER a partial derivation. F035's FR-010/FR-011 (delegation) + FR-012a (fallback to pre-filter result on malformed callback return) enforce this directly. (Reason Included: F035's central architectural principle is delegation, exactly the defence B32 encodes)
- **B26 (F021)** — governance grep gates that hard-code a directory allow-list silently skip newly-added layers. F035 introduces SC-005 (`public/Discovery/` scope) and SC-006 (`includes/` scope) — both allow-lists. If a future refactor adds `public/DiscoveryV2/` or moves the class, the gates silently stop enforcing. (Reason Included: F035's own SC gates carry B26 risk)
- **B23** — test-suffix method names (`_reset_for_tests()`) are silent-regression smells. F035's memoization (FR-005) needs a request-scope reset for the PHPUnit suite. Plan-phase decision: prefer production-shape naming (`clear_memoization()` / `flush()`) OR bootstrap-side reset via reflection. Do NOT ship a `_reset_for_tests()` method that production could accidentally depend on. (Reason Included: F035 test suite will fight memoization; naming choice is a plan-phase decision point)

## Conflict Warnings

None. All spec clarifications (Q1 later-wins dedup, Q2 SEC-013-008 pattern, Q3 fallback-to-pre-filter) align with existing durable memory (D35 + B32).

## Retrieval Notes

- Index entries considered: 20 (targeted read of §Active Decisions D22, D35, DEC-CLIENT-RENDERER-PUBLIC-API; §Architecture Constraints A1, A2, A6, A11, A18; §Bug Patterns B23, B26, B32; §Worklog F017, F020, F030 for precedent-context; §Accepted Deviations scanned, none applicable; §Security Constraints scanned, none applicable).
- Source sections read: `docs/memory/INDEX.md` (both pages, 187 lines). No source-body reads needed — every relevant entry's INDEX row already carries enough detail.
- Budget status: 3 decisions / 5 constraints / 0 deviations / 0 security / 3 bug patterns / 0 worklog cited (within limits: 5 / 5 / 3 / 3 / 3 / 2 caps).
- Optimizer: DISABLED (`optimizer.enabled: false`) — markdown-only index-first flow used.
- Word count target: 900 max; this synthesis ≈ 870 words.
