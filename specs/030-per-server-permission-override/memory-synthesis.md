# Memory Synthesis

## Current Scope

Feature 030 — Per-Server Ability Permission-Callback Override. Adds a `tinyint(1) override_abilities_permission` column to `wp_acrossai_mcp_servers` (BerlinDB `MCPServer\Table` 1.1.1 → 1.1.2). Extends `admin/Partials/ServerTabs/AccessControlTab::render_body()` with a new form (below the wpb-ac panel, `<hr>`-separated), a warning banner when ON, a native `confirm()` on save-to-ON, plus a promotional card for the sibling `acrossai-abilities-manager` plugin via the `acrossai_addons` filter + `main-menu` package's `AddonsAjaxHandlers`. Adds a new `includes/Abilities/PermissionOverrideProcessor` singleton that hooks `wp_register_ability_args` at priority **999999** and swaps every ability's `permission_callback` for a `CurrentServerHolder`-scoped closure returning `true` when override is ON for the current server and the ability is exposed to it, else falling through to the original callback.

## Relevant Decisions

- **D28 / DEC-BERLINDB-SCHEMA-DRIFT-RECONCILIATION** (Reason: this feature adds a column to a live BerlinDB table; must ship the 3-part contract — bump `$version`, register `$upgrades['1.1.2' => 'upgrade_to_1_1_2']`, rely on `Main::reconcile_database_schemas()` at `admin_init` P3. Status: Active, F029. Source: DECISIONS.md D28.)
- **D25 / DEC-F026-WP-REGISTER-ABILITY-ARGS-CALLBACK-SWAP** (Reason: F030's `PermissionOverrideProcessor` is a direct sibling of `CallbackReplacer` — same hook, same callback-swap pattern, higher priority. Reject alternative of hooking `mcp_adapter_pre_tool_call` (would collide with F015/F017/F020 gate priority map per DEC-F020-TOOL-ENFORCEMENT-PRIORITY). Status: Active, F026 v3. Source: DECISIONS.md D25.)
- **DEC-ABILITY-OVERRIDE-RESOLUTION** (Reason: F030's closure must gate on `ExposureResolver::resolve()` — not re-derive per-server exposure — matching the F017 single-resolver invariant. Status: Active, F017. Source: DECISIONS.md.)
- **D26 / DEC-CONSUMER-SELF-EXCLUSION-VIA-VENDOR-FILTER** (Reason: F030 uses the same `acrossai_addons` filter mechanism, but ADDING an entry for `acrossai-abilities-manager` rather than removing self. D26 documents the vendor filter contract we're extending. Status: Active, F028. Source: DECISIONS.md.)
- **DEC-BERLINDB-TABLE-REQUEST-BOOT** (Reason: `MCPServer\Table` is already request-boot-instantiated via `Main::load_hooks()`; F030 must not regress this. No new BerlinDB Table is added. Status: Active, F011. Source: DECISIONS.md.)

## Active Architecture Constraints

- **A17 — Request-scoped WP REST context capture** (Reason: F030's closure resolves current server via `CurrentServerHolder::instance()->get_server_id()`, which is the canonical A17 implementation. The 3-hook contract — `rest_pre_dispatch` P5 populate, `rest_post_dispatch` P999 clear, `shutdown` P999 safety-net — is already wired; F030 depends on but does not modify it. Source: ARCHITECTURE.md A17.)
- **A1 — Hook registration in `Main.php`** (Reason: `PermissionOverrideProcessor::boot()` matches the sibling plugin's inline `add_filter` pattern from a `boot()` invoked by `Main::load_hooks()` — the same shape as `AcrossAI_Ability_Override_Processor::boot()`. This is a scoped A1 carve-out precedented by DEV4 + D18/D25 wiring. Source: ARCHITECTURE.md A1.)
- **A2 — Singleton pattern** (Reason: New `PermissionOverrideProcessor` class MUST implement `protected static $_instance` + public `instance()` + private `__construct()`. Source: ARCHITECTURE.md A2.)
- **A3 — Admin UI in `admin/Partials/`** (Reason: New `AbilitiesManagerPromoCard` and edits to `AccessControlTab` all live under `admin/Partials/ServerTabs/*`. Source: ARCHITECTURE.md A3.)
- **A16 — Composer VCS entry for `acrossai-co/*`** (Reason: The `main-menu` package the promo card reuses is already declared with a `repositories` VCS entry; no new composer deps needed. Source: ARCHITECTURE.md A16.)

## Accepted Deviations

- **DEV1 — MCP Manager parent menu WP_List_Table exception** (Reason: server-edit tabs are child pages of this exception; existing hand-rolled admin form precedent covers F030's new form section per A10. Status: Accepted-Deviation, permanent. Source: CONSTITUTION.md §IV.)
- **DEV4 — Shared vendor bootstrap A1 exception** (Reason: F030's `PermissionOverrideProcessor::boot()` inline `add_filter` pattern draws on the same A1-relaxation lineage as DEV4 — scoped, gated, precedented. Status: Accepted-Deviation. Source: DECISIONS.md D15 / Feature-010.)

## Relevant Security Constraints

- **S1 — Nonce on forms/AJAX** (Reason: new save handler uses `check_admin_referer('acrossai_mcp_manager_permission_override_' . $server_id, …)`; existing `acrossai_addons` AJAX handlers reuse `acrossai_addons` nonce. Source: CONSTITUTION.md §III.)
- **S6 — Private singleton `__construct()`** (Reason: `PermissionOverrideProcessor::__construct()` MUST be private to prevent duplicate filter registration (double-firing = double `wp_register_ability_args` wrap). Source: PROJECT_CONTEXT.md.)
- **S4 — `$wpdb->prepare()`** (Reason: The `upgrade_to_1_1_2()` ALTER uses backtick-quoted `{$table}` identifier interpolation only — matches D28 reference impl. Column reads via BerlinDB `Query::query()` are parameterised. Source: CONSTITUTION.md §III.)

## Related Historical Lessons

- **B18 — `$wpdb` returns TINYINT as string** (Reason: The new `override_abilities_permission` column is a tinyint. Row constructor MUST `(int)`-cast it (matching existing `tool_*` cast). Closure must compare via `0 === (int) …` or `! empty(…)`. Silent bug if strict `1 === $val` comparison used.)
- **B24 — Vendor accessor via `instanceof` silently fails** (Reason: The closure uses `CurrentServerHolder::instance()->get_server_id()` — a duck-typed accessor already resolved in the canonical F015/F017/F020 pattern. No new vendor `instanceof` in F030.)
- **B34 — Silent write-loss on BerlinDB schema drift** (Reason: This is the entire origin story of D28 which F030 must follow exactly. F029's `Main::reconcile_database_schemas()` at `admin_init` P3 is the required harness.)

## Conflict Warnings

**SOFT CONFLICT** with **D24 / DEC-F026-ADVERTISEMENT-VS-CALL-TIME-DEFENSE-IN-DEPTH** — corollary v3 states:

> "exposure ≠ authorization — companion filters widening exposure MUST NOT bypass the target ability's own `permission_callback`"

F030 **explicitly bypasses `permission_callback`** by design. This is not an accidental leak — it is an operator-chosen opt-in gated by:
1. `manage_options` capability on the save handler.
2. Per-server nonce `acrossai_mcp_manager_permission_override_{server_id}`.
3. Persistent warning banner rendered when the flag is ON (FR-016).
4. Native `confirm()` prompt on submit-to-ON (FR-017).
5. Scope narrowed by `CurrentServerHolder` — override applies ONLY to in-flight MCP requests to the specific server; site-wide callers (WP admin, non-MCP REST, WP-CLI) fall through to the original callback per FR-008/FR-009.
6. Further narrowed by `ExposureResolver::resolve()` — override applies ONLY to abilities exposed via `wp_acrossai_mcp_server_abilities` for the current server per FR-010.

Recommendation: mark F030 as introducing **DEC-F030-PERMISSION-CALLBACK-OPERATOR-OPT-IN-BYPASS** (Active) — a scoped carve-out to D24's corollary, cited as an operator-visible security setting with belt-and-suspenders UX friction. D24 remains the default rule for every filter that widens exposure without operator opt-in.

**No hard conflicts.** Progress may proceed to `/speckit-plan`. The soft conflict MUST be captured as a new decision entry via `/speckit-memory-md-capture` after implement.

## Retrieval Notes

- Index entries considered: 20 of 20 budget (5 decisions selected from 28; 5 architecture constraints from 17; 3 accepted deviations from 4; 3 security constraints from 9; 3 bug patterns from 34; 2 worklog items from 12).
- Source sections read: `docs/memory/INDEX.md` in full (compact routing map). No durable memory sources (ARCHITECTURE.md, DECISIONS.md, BUGS.md, WORKLOG.md, PROJECT_CONTEXT.md, CONSTITUTION.md) were read at length — index entries provided sufficient detail for planning-phase synthesis.
- Budget status: under `max_synthesis_words: 900` (~ 780 words body). Optimizer disabled; markdown-only retrieval used.
