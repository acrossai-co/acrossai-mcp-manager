# Memory Synthesis

**Feature**: 001 — Core Boot Flow — WPBoilerplate Loader Migration
**Created**: 2026-05-29
**Status**: archived (merged to docs/memory 2026-05-29)

## Current Scope

Feature 001 wires the existing WPBoilerplate `includes/Main.php` to the full
module roster, fixes a null-property constant bug, adds `includes/Compat.php`,
and extends `includes/Activator.php`. No new admin UI, no new REST routes in
this phase. Affected files: `includes/Main.php` (extend), `includes/Compat.php`
(new), `includes/Activator.php` (extend), `acrossai-mcp-manager.php` (minor).

## Relevant Decisions

No active decisions in DECISIONS.md yet — this is the first feature. Decisions
produced by this phase should be promoted after implementation.

## Active Architecture Constraints

- A1: All hook registration MUST live exclusively in `Main::define_admin_hooks()`
  and `define_public_hooks()` via the Loader. Zero `add_action()`/`add_filter()`
  calls in any class constructor. (Reason Included: Core invariant for FR-001,
  FR-006, FR-007; Source: ARCHITECTURE.md)
- A2: Every feature class MUST use `protected static $_instance` + `public static
  function instance(): self`. Named-variable assignment REQUIRED before Loader
  wiring — passing `FeatureClass::instance()` inline to `add_action()` is
  prohibited. (Reason Included: Governs all module instantiation in
  define_admin_hooks(); Source: ARCHITECTURE.md)
- A3: Admin UI classes live in `admin/Partials/`; namespace
  `AcrossAI_MCP_Manager\Admin\Partials`. `includes/` classes are context-neutral
  and MUST NOT contain admin-specific logic. (Reason Included: Governs where
  Admin\Main, Menu, Settings, ApplicationPasswords live; Source: ARCHITECTURE.md)

## Accepted Deviations

- DEV1: `WP_List_Table` for the `?page=acrossai_mcp_manager` parent menu page.
  (Reason Included: Boundary condition for `admin/Partials/Menu`; does NOT apply
  to any new screens in this phase; Status: Accepted-Deviation; Source:
  CONSTITUTION.md §IV)

## Relevant Security Constraints

- ABSPATH guard (`defined('ABSPATH') || exit`) required at top of every PHP file.
  Applies to all new/modified PHP files in this phase. (Source: CONSTITUTION.md §III)
- S4: All DB queries MUST use `$wpdb->prepare()`. In this phase, table creation
  is delegated to `Query::maybe_create_table()` (Phase 4 classes) — S4
  enforcement deferred to Phase 4. Any direct `$wpdb` calls inside
  `Activator::activate()` must still use `prepare()`. (Source: CONSTITUTION.md §III)

## Related Historical Lessons

- No entries in BUGS.md yet. One bug derived from spec clarification: in the
  existing `includes/Main.php`, `ACROSSAI_MCP_MANAGER_PLUGIN_NAME_SLUG` is
  passed `$this->plugin_name` which is `null` at call time — `define_constants()`
  runs before `$this->plugin_name = 'acrossai-mcp-manager'` is set (FR-003).

## Conflict Warnings

None detected. The spec (FR-001 to FR-010) and all three clarifications (Q1–Q3)
are fully consistent with A1, A2, A3, the ARCHITECTURE.md boot flow, and
CONSTITUTION.md §III.

## Retrieval Notes

- Index entries considered: A1, A2, A3 (applied); A4 (excluded — no new UI);
  A5 (excluded — no MCP listing); DEV1 (applied as boundary note); S1 (excluded —
  no forms); S2 (excluded — no REST routes); S3 (excluded — no auth tokens);
  S4 (partially applied — DB delegated to Phase 4).
- Source sections read: INDEX.md (full), ARCHITECTURE.md §boot-flow + §singleton
  + §namespace-map + §boundaries, DECISIONS.md (stub), BUGS.md (stub).
- Budget status: 3/5 architecture constraints, 0/5 decisions, 1/3 deviations,
  2/3 security constraints, 0/3 bug patterns, 0/2 worklog. Well under limit.
