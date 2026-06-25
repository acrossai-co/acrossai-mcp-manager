# Memory Synthesis

## Current Scope

Phase 6 — **REST CLI Authentication Controller** + Phase 6.0 absorbed `Public\Partials\FrontendAuth`. Three new files: `Includes\REST\CliController.php` (5 routes + 1 static method + 4 class constants + Q2's Content-Type guard at FR-015), `Public\Partials\FrontendAuth.php` (4 Loader callbacks + static `get_base_url()` + 4 private renderers), `Includes\Database\CliAuthLog\Recorder.php` (per Q1 — stateless A11-style helper class with static `record_approved()` + `record_success()` calling into Phase 2's `Query::add_item()`). One extension: `includes/Activator.php` + `includes/Main.php` wiring 4 FrontendAuth callbacks + 1 CliController callback (`rest_api_init`). No new DB tables.

**Refreshed 2026-06-25** after `/speckit-clarify` resolved Q1 (Recorder location), Q2 (Content-Type allow-list), Q3 (App Password naming uniqueness), Q4 (session token bound to `server_id`).

## Relevant Decisions

- **D5** — PHPCS baseline exceptions preserved. (Reason: 3 new files will hit `$_instance` prefix + filename casing rules. Source: INDEX.md.)
- **D11** — Phase X.0 absorption. (Reason: Phase 6.0 absorbs the FULL FrontendAuth class per the planning-input design — largest absorption surface so far at ~180 lines vs Phase 5.0's ~60-line WP-PHPUnit harness. Spec §Dependencies marks Phase 3 ⚠ resolved. Source: INDEX.md.)
- **D12** — Bulk task-status re-audit discipline. (Reason: this phase will generate 60-80 tasks; implementer MUST follow D12 to avoid Phase 5's false-`[x]` regression. Source: DECISIONS.md, 2026-06-25.)

## Active Architecture Constraints

- **A1** — Hook registration only in `includes/Main.php`. (Reason: FR-011 mandates `rest_api_init` + 4 FrontendAuth hooks live in `Main::define_public_hooks()`. Zero `add_action`/`add_filter` in either feature class. Source: ARCHITECTURE.md.)
- **A2** — Singleton + private ctor for feature classes. (Reason: FR-010 enforces this for CliController + FrontendAuth. The static `CliController::approve_auth_code()` does NOT trigger singleton — operates on transients directly. Source: ARCHITECTURE.md.)
- **A6** — `use` imports / leading-`\` FQN inside `Includes\*` + `Public\*` files. (Reason: CliController references `Includes\Database\MCPServer\Query`, `Includes\Database\CliAuthLog\Recorder` (NEW per Q1), `Public\Partials\FrontendAuth`, WP-core `WP_Application_Passwords`. FrontendAuth references `Includes\REST\CliController` for the static call. Bare relative names silent-fail per B1. Source: ARCHITECTURE.md.)
- **A11** — Pure service classes exempt from singleton. (Reason: **APPLIED to the new `CliAuthLog\Recorder` class** per Q1 — stateless static-method-only helper, no instance state, no hooks. Parallel to PKCE (Phase 5) which is a math utility. Source: ARCHITECTURE.md.)
- **A14** — WP-CLI dispatch classes A11-style exempt. (Reason: NOT directly applied here, but `Recorder` (Q1) extends the A11/A14 carve-out family to "Database\<Module>\ static recorder helpers". After impl validates the pattern, consider promoting an **A15 candidate** — "Database-namespace audit-recorder static helpers follow A11/A14 family". Source: ARCHITECTURE.md, captured 2026-06-25.)

## Accepted Deviations

None directly relevant.

## Relevant Security Constraints

- **S2** — REST routes have explicit `permission_callback`; `__return_true` only on public read routes. **Soft conflict flagged**: FR-012 uses `__return_true` on 4 of 5 routes. Q2 + Q4 added defense-in-depth (Content-Type strict + server-binding) that materially narrow the attack surface compared to a naive `__return_true` deployment. **S8 capture queued** post-implementation — "body-authenticated mutating REST routes broader than OAuth-token-endpoint; Content-Type allow-list + per-route mutation-bound test required". Source: CONSTITUTION.md §III.
- **S6** — Singleton `__construct()` MUST be private. (Reason: FR-010 enforces. Source: PROJECT_CONTEXT.md.)
- **S7** — OAuth token endpoint S2 exemption (Phase 5 precedent). **Directly inherited** by Q2 + Q4 reasoning: Q2 inherits Phase 5 SEC-002's Content-Type rejection lesson; Q4 inherits Phase 5 FR-015's DB-level cross-server defense pattern. Source: PROJECT_CONTEXT.md, 2026-06-25.

## Related Historical Lessons

- **B1** — Namespace silent-fail inside `Includes\*` + `Public\*` files. (Reason: 3 new files cross-namespace; `use` imports MUST be present.)
- **B5** — Public ctor on singleton allows double registration. (Reason: 5 REST routes + 4 Loader hooks — accidental re-instantiation would register everything twice.)
- **B10** — Check-then-act on one-shot credentials → atomic CAS. **Plan-time accepted deferral confirmed**: `/auth/exchange` uses non-atomic `get_transient + delete_transient`. Q4's `server_id` binding narrowed the threat model further — even a race-loss only lets an attacker obtain an App Password scoped to the consented server, never beyond. Per plan.md §Complexity Tracking row 3: B10 absorption rejected at planning. Source: BUGS.md, captured 2026-06-25.

## Conflict Warnings

- **Soft conflict — S2 vs FR-012 `__return_true` on 4 of 5 REST routes**: broader exemption than S7. **Q2 + Q4 materially mitigated** via Content-Type strict allow-list + session token server-binding. Spec documents the rationale; **S8 capture queued post-implementation**.
- **Soft conflict — B10 vs FR-007 `/auth/exchange` redemption**: spec defers as known follow-up; Q4's server-binding further narrowed the attack surface. **Plan-time accepted**.
- **RESOLVED — D11 Phase 6.0 FrontendAuth absorption**: was flagged as soft conflict in the pre-Q4 synthesis. Q4 + planning input confirmed full FrontendAuth absorption is the intended path. No longer a conflict.
- **RESOLVED — Session token scope**: original spec §Assumptions had "session token NOT bound to server" — Q4 reversed this to match Phase 5 FR-015 cross-server defense. Spec is now internally consistent.

No hard conflicts. No constitution MUST is violated.

## Retrieval Notes

- Index entries considered: 18 (D5/D11/D12, A1/A2/A6/A11/A14, B1/B5/B10, S2/S6/S7, plus A12/A13/DEV1/DEV2 surveyed and rejected as non-applicable).
- Source sections read: INDEX.md only. ARCHITECTURE.md A11/A14 detail re-read to confirm Recorder fits the carve-out family (per Q1).
- Budget status: 18/20 entries · 3/5 decisions · 5/5 architecture · 0/3 deviations · 3/3 security · 3/3 bugs · 0/2 worklog.
- Synthesis word count: ~888 / 900-word cap.
- **Refresh trigger**: Q1–Q4 clarifications landed in spec on 2026-06-25; this synthesis re-evaluates conflicts (2 mitigated, 2 resolved) and surfaces A15 candidate (Database-namespace static recorder family). No new HARD conflicts introduced.
