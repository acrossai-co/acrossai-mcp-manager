# Memory Synthesis

## Current Scope

Feature 069 — MCP Quick Setup Wizard. Net-new admin surface: a 5-step React wizard at `?page=acrossai_mcp_manager&quick-setup=1`, admin-bar chip (`Quick Setup for MCP`), activation-time redirect (no banner), new REST namespace `/acrossai-mcp-manager/v1/quick-setup/*`. **Additive-only**: no changes to existing per-server-edit tabs, REST routes, DB schema, or wp_options. Reuses `MCPServerQuery`, wpb-ac `RuleQuery` (via F015 wrapper), F017 abilities controller, `ConnectionMethodRegistry` (F035). Two transients (site-level activation-redirect 30s TTL; per-user scratchpad 30-min TTL). WCAG 2.1 AA. No observability hooks.

## Relevant Decisions

- **D37 / DEC-ADMIN-UI-REACT-FIRST** — Interactive multi-field admin UIs MUST use React + REST per F017/F020/F037 pattern. Hand-rolled forms + inline vanilla JS as React substitute prohibited. Wizard IS this shape — binding constraint. (Status: Active F037; Source: DECISIONS.md)
- **DEC-WP-DATAVIEWS-OVER-REACT** — New admin JS surfaces MUST use `@wordpress/dataviews` + `components` + `element` + `api-fetch` + `i18n` + `hooks`. `react-query`, `redux`, `mobx`, `react-table`, MUI, `styled-components` FORBIDDEN. Applies to every wizard React file. (Status: Active F017; Source: DECISIONS.md)
- **D38 / DEC-REUSABLE-PRIMITIVE-REGISTER-EXCEPTION** — `AbstractReactMountServerTab::register()` is the canonical asset-enqueue + REST-wiring shape. Wizard doesn't extend this base but MUST mirror the `wp_enqueue_script` + `wp_localize_script` + `asset.php` manifest pattern from `admin/Main.php`. (Status: Active F037 Pivot C; Source: DECISIONS.md)
- **DEC-ACCESS-CONTROL-V2-ADOPTION** — F015 wrapper (`AcrossAI_MCP_Access_Control`) mediates all wpb-ac reads/writes. Step 2 MUST route through the wrapper's public API — never `RuleQuery` directly. (Status: Active F015; Source: DECISIONS.md)
- **DEC-ABILITY-OVERRIDE-RESOLUTION** — Single resolver: `ExposureResolver::resolve()`. Step 3 "Enable all abilities" MUST call the F017 controller / resolver, never derive fallback state on its own. (Status: Active F017; Source: DECISIONS.md)

## Active Architecture Constraints

- **A1** — All `add_action`/`add_filter` in `includes/Main.php` via `define_admin_hooks()` / `define_public_hooks()`. Wizard admin-bar hook, `admin_init` redirect, REST registration, page-render hijack: ALL wire in Main.php. (Source: ARCHITECTURE.md)
- **A2** — Singleton `instance()` pattern with protected `$_instance` + private `__construct()`. All 4 new classes comply. (Source: ARCHITECTURE.md)
- **A3** — Admin UI classes live in `admin/Partials/` with matching namespace. Wizard's admin-side classes land under `admin/Partials/QuickSetup/`. (Source: ARCHITECTURE.md)
- **A4** — DataForm for forms; DataViews for lists (both from `@wordpress/dataviews`). Wizard's server picker → DataViews; server-create form + AC editor → DataForm. (Source: ARCHITECTURE.md)
- **A17** — Request-scoped REST context capture pattern (3 hooks incl. shutdown safety-net). Wizard's REST controller does NOT need this — payloads carry `server_id` explicitly, no callback-swap indirection. Cited for negative reference (rules out over-engineering). (Source: ARCHITECTURE.md)

## Accepted Deviations

- **DEV5** (narrowed post-D37) — Hand-rolled per-server-edit tab forms allowed ONLY for read-only + single-submit ≤3-field cases. **Wizard is interactive multi-field → DEV5 DOES NOT apply.** Wizard MUST route through D37 (React + REST). Explicit negative-application note. (Status: Active but out-of-scope here)

## Relevant Security Constraints

- **S1** — All forms + AJAX endpoints verify nonce. Wizard's mutating REST routes use `X-WP-Nonce` (`wp_rest` action). No `admin-post.php` handlers after Q2 pivot. (Source: security-constraints.md)
- **S2** — REST routes have explicit `permission_callback`; `__return_true` only on public read routes. **All three wizard REST routes are `manage_options`-gated** — callback returns bool from `current_user_can()`. (Source: security-constraints.md)
- **S6** — Singleton `__construct()` MUST be private — public ctor double-fires hooks (B5). All 4 new classes MUST comply. (Source: security-constraints.md)

## Related Historical Lessons

- **F037 (2026-07-28)** — Reusable React-mount primitive shipped with 3 post-plan pivots. Codified D37 + D38. Lesson: use `/speckit-analyze` post-implement + Path A retrofit workflow when mid-implementation pivots outrun the spec. Applies here — wizard already absorbed a mid-clarify pivot (banner → activation redirect), so a similar analyze-retrofit dance is likely.
- **F040 (2026-07-31)** — Cross-plugin subsystem migration proved the value of an "additive-only + probe" contract. Wizard's FR-029/FR-030 mirror this — verify with post-implementation grep: zero non-additive edits under `admin/Partials/ServerTabs/`, `includes/REST/` (except new controller), `includes/Database/`.

## Conflict Warnings

- **Soft — B22 (WordPress package externals map)**: Spec mandates `@wordpress/dataviews` (DataForm + DataViews). Some minor `@wordpress/*` packages remain v0.x and are NOT in `@wordpress/scripts` externals — build-time import silently bundles under an unregistered handle → React app boots but never populates. Plan MUST verify `@wordpress/dataviews` externals coverage on WP 6.9+ before finalising webpack entry; if missing, specify runtime-lookup fallback per B22. Non-blocking; call out in `plan.md` Constitution Check.
- **Soft — B14 (activation hook priority)**: FR-003/004/005 wire an activation-time signal. `register_activation_hook` default priority 10 fatals before higher-priority guards can run. Plan MUST place the `set_transient` at priority 1 (matches the plugin's existing vendor-autoload guard pattern in `acrossai-mcp-manager.php`) OR wrap in `function_exists('set_transient')` so activation on a broken-autoload state never fatals before the wizard loads.

**No hard conflicts.** Feature is compatible with constitution, cited decisions, and active architecture constraints.

## Retrieval Notes

- Index entries considered: 20 (10 decisions → top 5; 6 architecture constraints → top 5; 4 accepted deviations → 1 negative-application; 3 security constraints of 9; 4 bug patterns → 2 as soft conflicts; 12 worklog entries → top 2).
- Sources read: `config.yml`, `INDEX.md` (both pages, 208 lines total).
- Full-file reads avoided: DECISIONS.md, ARCHITECTURE.md, BUGS.md, WORKLOG.md — consulted via INDEX rows only per `full_memory_read_allowed: false`.
- Budget: **under 900 words**. Limits: 5/5 decisions, 5/5 constraints, 1/3 deviations, 3/3 security, 2/2 worklog, 2 conflict warnings.
- **Durable Memory Preservation check** — no new architectural patterns identified this turn. Wizard is a clean application of existing decisions (D37, D38, DEC-WP-DATAVIEWS-OVER-REACT); no novel decisions to enshrine via `/speckit.memory-md.capture` yet.
