# Memory Synthesis

## Current Scope

Phase 2 — **Admin UI migration**. Port six classes from source `src/Admin/`
into `admin/Partials/` with namespace `AcrossAI_MCP_Manager\Admin\Partials`;
replace every Phase 1 `// TODO` stub in `Includes\Main::define_admin_hooks()`
with Loader wiring; swap all `MCPServerTable::` static calls for the BerlinDB
`MCPServer\Query` instance pattern; enforce asset-enqueue screen guard.
Affected modules: `admin/Partials/*`, `admin/Main.php`, `includes/Main.php`.
Hard prerequisite: `Includes\Database\MCPServer\Query` and `CliAuthLog\Query`
must exist before any task begins (currently absent — flagged P0).

## Relevant Decisions

- **D5** — PHPCS baseline exceptions (filename casing, `$_instance` prefix,
  file docblocks, `namespace Public`, PSR12 header) MUST remain in
  `phpcs.xml.dist`. (Reason: every new admin partial uses `$_instance` and
  triggers these rules. Status: Active. Source: DECISIONS.md.)
- **D6** — `use` imports (not bare relative names) MUST be used for cross-
  namespace class references inside any `AcrossAI_MCP_Manager\Includes` file.
  (Reason: `Includes\Main` edits in this phase reference
  `Admin\Partials\Settings` etc. Status: Active. Source: DECISIONS.md.)
- **D8** — Access Control wiring targets vendor FQN
  `\WPBoilerplate\AccessControl\AccessControlManager` (Composer pkg
  `wpb-access-control ^1.0`). (Reason: FR-011 Access Control tab + Loader
  guard. Status: Active. Source: DECISIONS.md.)

## Active Architecture Constraints

- **A1** — All hook registration lives exclusively in `includes/Main.php`
  via `define_admin_hooks()` / `define_public_hooks()`. (Reason: FR-020 /
  FR-021. Source: ARCHITECTURE.md.)
- **A2** — Every feature class uses the singleton `instance()` pattern
  (`protected static $_instance` + `public static instance(): self` +
  `private __construct()`). (Reason: directly governs the clarification-Q1
  reconciliation that overrode user's constructor-injection sketch. Source:
  ARCHITECTURE.md.)
- **A3** — All admin UI classes live in `admin/Partials/` with namespace
  `AcrossAI_MCP_Manager\Admin\Partials`. (Reason: FR-024, every new file
  in this phase. Source: ARCHITECTURE.md.)
- **A4** — All new data forms use `DataForm`; all new lists use `DataViews`.
  (Reason: tension with this phase's `WP_List_Table` + tabbed-form usage;
  resolved by DEV1 below. Source: ARCHITECTURE.md.)
- **A6** — Inside `AcrossAI_MCP_Manager\Includes` files, sub-namespace
  references MUST use `use` imports or leading-`\` FQN; bare relative
  names silently fail. (Reason: `Includes\Main` will reference
  `Admin\Partials\Settings` — bare `Admin\Partials\Settings` would
  resolve as `Includes\Admin\Partials\Settings`. Source: ARCHITECTURE.md.)

## Accepted Deviations

- **DEV1** — MCP Manager parent menu uses `WP_List_Table` and tabbed
  settings form instead of DataViews/DataForm. (Reason: pre-approved
  exception in Constitution §IV; Phase 2 ports both the parent menu list
  AND the CLI Auth Log submenu under this same exception per spec.md
  Admin UI Requirements. Status: Accepted-Deviation, never expires.)

## Relevant Security Constraints

- **S1** — All forms and AJAX endpoints MUST verify a nonce. (Source:
  CONSTITUTION.md §III. Applies to FR-007, FR-007a, FR-013, FR-015
  notice-dismiss endpoint, every list-table bulk/row action.)
- **S5** — `admin_url()` MUST be wrapped with `esc_url()` before HTML
  output because it is filterable via the `admin_url` hook (XSS risk).
  (Source: PROJECT_CONTEXT.md. Applies to FR-003 plugin-action-link
  rendering and any "Settings" / "Edit" / "Add New" anchors.)
- **S6** — Singleton `__construct()` MUST be `private` — public ctor
  allows duplicate instantiation and double hook firing. (Source:
  PROJECT_CONTEXT.md. Applies to every new partial class — and to the
  Q1 clarification rejecting `new Settings(...)`.)

## Related Historical Lessons

- **B1** — Namespace relative-path bug inside `Includes\*` files
  silently produces `class_exists() === false`. `Includes\Main` Phase 1
  stubs originally drifted into `Includes\Admin\Partials\...` and were
  fixed. **Mitigation for this phase**: Loader contract (loader-wiring.md)
  uses leading-`\` FQN (`\AcrossAI_MCP_Manager\Admin\...`) — safe but
  verbose; A6 prefers `use` imports.
- **B5** — Public constructor on singleton → double hook registration.
  Q1 clarification explicitly rejected `new Settings(...)` for this
  reason; every ported partial MUST keep `private __construct()`.
- **B6** — `admin_url()` without `esc_url()` permits XSS via the
  `admin_url` filter. Plugin-action-link "Settings" anchor MUST go
  through `esc_url(admin_url('admin.php?page=acrossai_mcp_manager'))`.

## Conflict Warnings

- **Soft conflict — A6 vs loader-wiring.md style**: The contract in
  `contracts/loader-wiring.md` uses leading-`\` FQN
  (`\AcrossAI_MCP_Manager\Admin\Partials\Settings::instance()`) rather
  than `use` imports. Both forms satisfy A6, but the constraint *prefers*
  `use` imports for readability. **Recommendation**: implementation may
  switch to `use` statements at the top of `includes/Main.php` and call
  `Settings::instance()` bare — functionally identical, marginally
  cleaner. Not blocking.

- **Soft conflict — A4 vs DEV1**: Phase 2 ports both the Servers list
  page (parent-menu carve-out, clean DEV1 match) AND the CLI Auth Log
  submenu list table (NOT the parent menu — same `WP_List_Table` pattern,
  same source repo). Spec.md §Admin UI Requirements asserts DEV1 covers
  the CLI Auth Log submenu too, because it is reached from the parent
  menu. **Recommendation**: this interpretation is documented in the
  spec and not blocking; if architecture-guard challenges it later, the
  spec's reasoning should hold.

No hard conflicts. Plan is compatible with all active memory.

## Retrieval Notes

- Index entries considered: 18 (D5 D6 D8, A1 A2 A3 A4 A6 A8, B1 B5 B6,
  DEV1, S1 S2 S5 S6, plus D1/D2/D4 surveyed for relevance and dropped
  as boot-flow-specific).
- Source sections read: INDEX.md only (per optimizer-disabled,
  budget-conscious flow). Full memory files NOT read — index entries are
  self-describing enough for this synthesis.
- Budget status: 18/20 entries · 3/5 decisions · 5/5 architecture ·
  1/3 deviations · 3/3 security · 3/3 bugs · 0/2 worklog
  (worklog skipped — Phase 2 has no prior history to draw from).
- Synthesis word count: well under 900-word cap.
