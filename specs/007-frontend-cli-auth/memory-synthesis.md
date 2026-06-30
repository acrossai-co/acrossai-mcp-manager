# Memory Synthesis

## Current Scope

Phase 7 (`007-frontend-cli-auth`) replaces `public/Partials/FrontendAuth.php` with a re-spec'd implementation. Four intentional changes: `QUERY_VAR` renamed to `acrossai_mcp_auth`; authorization broadened from `manage_options` → any logged-in user; inline `<style>` → externally-enqueued `build/css/frontend.css` (with RTL variant); nonce action simplified to action-only `cli_auth_approve`. Two clarifications added 2026-06-25: i18n text-domain wrapping required (FR-016); RTL CSS variant must be registered via `wp_style_add_data`. Implementation surface: ONE PHP file. Loader wiring + Activator rewrite-flush already in place from PR #8.

## Relevant Decisions

- **D11 — Phase X.0 Absorption Pattern** (Active, DECISIONS.md, 2026-06-18). Reason: this phase is the inverse case — Phase 6 already absorbed FrontendAuth as Phase 6.0. Phase 7 now *replaces* that absorbed code with the re-spec'd version. D11's "absorb prereqs, don't stall" lesson is now load-bearing in reverse: the absorbed code is the starting point, not a clean slate.
- **D5 — PHPCS baseline exceptions** (Active). Reason: FR-016 (i18n mandate) intersects with the WPCS strict ruleset; the existing baseline must continue to pass after the file replacement.

## Active Architecture Constraints

- **A1 — All hook registration in Main.php** (ARCHITECTURE.md). Reason: FR-014 mandates the 4 Loader-wired hooks; constructor remains empty. Verified: existing `Main::define_public_hooks()` lines 417–421 already wire `init/query_vars/template_redirect/wp_enqueue_scripts`.
- **A2 — Singleton + private ctor** (ARCHITECTURE.md). Reason: FR-002 mandates the exact pattern; no exception applies (this is not A11/A14/A15 stateless-helper family — FrontendAuth holds rendering logic and is hook-wired).
- **A6 — `use` imports or leading-`\` FQN in `Includes\*` / `Public\*`** (ARCHITECTURE.md). Reason: class imports `Includes\REST\CliController` for the static `approve_auth_code()` call. Must be a `use` statement at the top of the file, not bare `Includes\REST\CliController` (which would silently double the namespace per B1).
- **A9 — Shared admin constants in `includes/Utilities/`** (ARCHITECTURE.md). Reason: `PAGE_SLUG` and `QUERY_VAR` are class-local constants for now (only read by FrontendAuth + Activator + the Phase 6 `CliController::auth_start` consumer of `get_base_url()`). If a third consumer ever reads them, A9 promotion to `includes/Utilities/Constants.php` triggers.

## Accepted Deviations

- **DEV1 — MCP Manager parent menu uses WP_List_Table** (Never expires). Reason: NOT triggered by Phase 7 — no admin UI in this phase.

## Relevant Security Constraints

- **S1 — Nonces on form/AJAX handlers** (CONSTITUTION.md §III). Reason: FR-009 enforces `wp_verify_nonce` on the `cli_auth_approve` GET branch BEFORE state mutation.
- **S6 — Singleton `__construct` MUST be private** (PROJECT_CONTEXT.md). Reason: FR-002 enforces `private function __construct() {}`. Prevents B5 (double registration).
- **S8 — Body-authenticated mutating REST routes broader than S7** (PROJECT_CONTEXT.md, captured Feature-006). Reason: NOT triggered here — this phase has no REST routes. S8 is the Phase 6 CliController's exemption, not Phase 7's. Phase 7 dispatches via WP nonces, not bearer tokens.

## Related Historical Lessons

- **B5 — Public ctor on singleton → double hook registration** (BUGS.md). Reason: A2 + FR-002 prevent it; verify in tests by asserting `instance() === instance()` after multiple calls.
- **B4 — Unescaped dot in PCRE rewrite rule** (BUGS.md). Reason: NOT triggered — the rewrite pattern `'^acrossai-mcp-manager/?$'` contains no literal dot. Documented in plan §Constitution Check.

## Conflict Warnings

- **Phase 7 spec FR-007.4 (no `manage_options` check) vs Constitution §III "Admin actions MUST enforce a capability check"**: Soft conflict. The consent surface is NOT an "admin action" — it is a user-on-own-behalf consent click. Spec §Assumptions and plan §Complexity Tracking document the threat-model rationale (App Password is scoped to the consenting user's capabilities; bounded blast radius). No constitution amendment required; this is a feature-local deviation, not a reusable pattern.
- **Phase 7 nonce action `cli_auth_approve` (action-only) vs existing impl per-code `cli_auth_approve_<code>`**: Soft conflict resolved in research.md §R1. Action-only is sufficient because `CliController::approve_auth_code()` enforces single-use downstream via the `pending` status check. Action-only is the WP-core convention (e.g. `delete-comment`).

## Retrieval Notes

- Read: `config.yml`, `INDEX.md` (entire — 72 lines), `DECISIONS.md` §D11 (40 lines), `ARCHITECTURE.md` §FrontendAuth registry (5 lines) + §A11 family (40 lines), `PROJECT_CONTEXT.md` §S8 (1 line), `BUGS.md` §B11 (5 lines).
- Index entries scanned: 20 (D1–D12, A1–A15, B1–B11, DEV1–DEV2, S1–S8).
- Selected: 2 decisions, 4 architecture constraints, 1 accepted-deviation reference, 3 security constraints, 2 bug patterns. Within budget (5/5/3/3/3).
- Budget status: ≤900 words. Full memory read NOT performed.
- No hard conflicts. Two soft conflicts surfaced + resolved in spec/plan/research.
