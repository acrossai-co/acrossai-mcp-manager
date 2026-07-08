# Memory Synthesis

## Current Scope

Feature 017 — Per-server Ability Selection. Adds one new BerlinDB module (`MCPServerAbility` — Schema/Table/Query/Row + stateless `ExposureResolver`), one new REST controller under the existing `acrossai-mcp-manager/v1` namespace with GET+POST for per-server ability exposure, replaces the read-only `AbilitiesTab::render_body()` body with a `<div>` mount for a `@wordpress/dataviews`-driven React app, adds one new webpack entry (`js/abilities`), and gates enqueue to the Abilities tab. Touches: `includes/Database/MCPServerAbility/*`, `includes/REST/AbilitiesController.php`, `includes/Activator.php` (+1 line), `includes/Main.php` (+2 lines — bootstrap + REST wiring), `admin/Main.php` (new `maybe_enqueue_abilities_app()`), `admin/Partials/ServerTabs/AbilitiesTab.php` (body only), `webpack.config.js`, `uninstall.php`, `package.json`, plus new `docs/extending-abilities-tab.md` for the companion-plugin extensibility surface.

**Extensibility contract (added 2026-07-07)**: three `@wordpress/hooks` JS filters (`acrossaiMcpManager.abilities.fields`, `.actions`, `.row`) + one PHP filter (`acrossai_mcp_ability_row`). Companion plugins (e.g. `acrossai-abilities-manager`) inject columns and per-row actions without touching this plugin's source. Built-in columns are re-asserted after each filter fires so extensions cannot remove or overwrite them. Defensive boundaries (`safeApplyFilters` in JS + array-return guard in PHP) ensure third-party failure never white-screens the tab.

**Call-time enforcement (added 2026-07-07 Q4 — SEC-001 closure)**: New callback on `mcp_adapter_pre_tool_call` at priority 20 (after F015's priority 10) consults `ExposureResolver::resolve()` and returns `WP_Error( 'acrossai_mcp_ability_not_exposed', ..., 403 )` when exposure is false. Deny-precedence enforced (F015 deny short-circuits F017); fail-open on unresolvable `$server_id`. List-time hiding deferred to a follow-up feature — hidden abilities may still appear in `mcp/tools/list` but calls are rejected.

## Relevant Decisions

- **DEC-BERLINDB-TABLE-REQUEST-BOOT** (Reason: F017 adds a new BerlinDB Table; must call `Table::instance()` in `Main::bootstrap_database_tables()` OR queries fall back to `$table_alias` FROM. Status: Active F011. Source: DECISIONS.md line 494.)
- **DEC-BERLINDB-SUBCLASS-NO-USE-COLLISION** (Reason: New `Includes\Database\MCPServerAbility\Table` extends `\BerlinDB\Database\Kern\Table` — same short name; must extend via leading-`\` FQN and drop `use`. Status: Active F011. Source: DECISIONS.md line 531.)
- **DEC-SERVER-TAB-CLASS-HIERARCHY** (Reason: `AbilitiesTab` stays under `AbstractServerTab` template-method; only `render_body()` body changes. Status: Active F013. Source: DECISIONS.md line 678.)
- **DEC-UNINSTALL-OPT-IN-GATE** (Reason: `uninstall.php` DROP for `acrossai_mcp_server_abilities` MUST live AFTER the `acrossai_mcp_uninstall_delete_data` opt-in short-circuit; default 0, preserve-by-default. Status: Active F012. Source: DECISIONS.md line 604.)
- **DEC-CLIENT-RENDERER-PUBLIC-API** (Reason: The four new extensibility hook names introduced by F017 form a cross-plugin public API contract — the same shape F013 established for the Renderer layer. Adopt the `@since 0.0.10 @experimental May change without notice before 1.0.0` stability marker on every filter/action docblock and mirror the `docs/integrations/*-example.md` doc convention when writing `docs/extending-abilities-tab.md`. Status: Active F013 (annotated F016). Source: DECISIONS.md line 707.)

## Active Architecture Constraints

- **Constitution Principle V — Extensibility Without Core Modification** (Reason: F017 IS the practical implementation of this principle for the Abilities tab — third parties integrate via `wp.hooks` + `apply_filters`, never by editing plugin files. FR-026..029 directly satisfy the constitutional mandate. Source: constitution.md §V.)
- **A1 — Hook registration only in `Main.php`** (Reason: REST controller wiring goes in `define_admin_hooks()`; PHP `add_filter( 'acrossai_mcp_ability_row', ... )` calls from companion plugins land in their own `Main.php`. Do NOT wire the filter's own execution in a class ctor. Source: ARCHITECTURE.md.)
- **A2 + S6 — Singleton `instance()` with private ctor** (Reason: `AbilitiesController` matches F011 shape; `ExposureResolver` uses the A11 pure-service exception. Source: ARCHITECTURE.md + PROJECT_CONTEXT.md.)
- **A4 + Constitution §IV — `@wordpress/dataviews` for new admin UI** (Reason: FR-016 pins the UI to `DataViews`; `@wordpress/hooks` is a Tier-1 companion per Principle VI. `DataForm` not applicable (not a data-entry form). Source: ARCHITECTURE.md + constitution.md §IV.)
- **A6 — Namespace hygiene in `Includes\`** (Reason: bare `Database\MCPServerAbility\Query` will double-prefix; use leading-`\` FQN or `use` imports. B1/B15 companions. Source: ARCHITECTURE.md.)

## Accepted Deviations

- None applicable to F017. DEV1 (WP_List_Table on parent menu) is unrelated; F017 stays in the DataViews mandate. No new deviation is proposed for this feature — the extensibility contract lives *inside* the constitution's Principle V, not around it.

## Relevant Security Constraints

- **S2 — Every REST route MUST have an explicit `permission_callback`; no `__return_true`** (Reason: Both GET and POST gate on `current_user_can( 'manage_options' )` per FR-012. The `acrossai_mcp_ability_row` filter runs INSIDE the authenticated request, so filter callbacks inherit the same auth guarantee — no additional check needed. Source: CONSTITUTION.md §III.)
- **S4 — All DB queries use `$wpdb->prepare()`** (Reason: BerlinDB's prepared layer satisfies this; no raw SQL added. Companion-plugin filter callbacks that add row data MUST NOT reach back to `$wpdb` without the same prepared-statement discipline. Source: CONSTITUTION.md §III.)
- **Principle III — sanitize at boundary / escape at render** (Reason: The `acrossai_mcp_ability_row` filter passes data BACK from third-party plugins into a REST response — the controller re-asserts built-in keys via `array_merge( $filtered, $row )`, which prevents overwrite but does NOT sanitize third-party additions. Downstream client-side render callbacks MUST treat filter-added values as untrusted. Source: constitution.md §III.)

## Related Historical Lessons

- **B17 — `rest_url()` trailing slash** (Reason: `maybe_enqueue_abilities_app()` MUST localize `restApiRoot` as `untrailingslashit( rest_url() )` — F015 precedent, else `//`-doubled routes 404 silently.)
- **B18 — `$wpdb` returns TINYINT columns as string** (Reason: `is_exposed` is `tinyint(1)`; strict `1 ===` compare always false. Cast `(bool)` before use in `ExposureResolver` and in any JS toggle rendering.)
- **B15 — Grep-gate regex leading-`\` FQN + short-name aliases** (Reason: the "no generic React libs" audit and the "filter name appears exactly once" TASK-9 grep gate both need two-pass verification if a companion plugin ever adds a namespaced FQN reference.)
- **WORKLOG 2026-07-02 F011 phantom-version guard** (Reason: `Table::maybe_upgrade()` MUST include the silent guard — F017 copies verbatim per Clarification Q1 silent-guard invariant.)
- **WORKLOG 2026-07-04 F015 → run `/speckit-analyze` after implement** (Reason: F017 has three Session-2026-07-07 clarifications and a scope-add (extensibility surface) — the pre-implement synthesis cannot catch drift between what the spec now promises and what ships. Post-implement analyze is mandatory.)

## Conflict Warnings

- **No hard conflicts.** Constitution Principle V explicitly mandates the shape F017 adopts (WP action/filter hooks for third-party integration). Every FR aligns with an active decision or constitutional principle.
- **No soft conflicts.** DEC-CLIENT-RENDERER-PUBLIC-API's `@experimental` marker becomes a soft *obligation* for the new filter names — surface in TASK-4/TASK-6 docblocks, not a conflict.
- **No superseded memory triggered.** D7 and D9 remain superseded by F011; F017 fully consumes the F011 shape.

## Retrieval Notes

- **Index entries considered**: 20 (all Active Decisions + Architecture + Security + Bug rows in INDEX.md).
- **Source sections read** (targeted, not full): `.specify/memory/constitution.md` full (288 lines — small enough), `docs/memory/DECISIONS.md` lines 707–748 for DEC-CLIENT-RENDERER-PUBLIC-API (the direct precedent for the new public-API contract). Did NOT open ARCHITECTURE.md, BUGS.md, PROJECT_CONTEXT.md, or WORKLOG.md bodies.
- **Budget status**: 5 decisions / 5 architecture (one entry is a constitution principle rather than an INDEX A-row — noted deliberately, replaces D19 in the top-5 since D19 guidance is fully embedded in FR-024) / 0 deviations / 3 security / 3 bug patterns / 2 worklog. Word count ~880 — within the 900 cap. Phase: **Plan** — prioritized boundary + module ownership + public-API stability.
- **Optimizer**: `optimizer.enabled: false` per config — markdown-only, index-first retrieval respected.
- **Refresh reason**: spec gained an extensibility contract (Q3 → FR-026..029, User Story 6, TASK-9). This synthesis supersedes the 2026-07-07 initial cut.
