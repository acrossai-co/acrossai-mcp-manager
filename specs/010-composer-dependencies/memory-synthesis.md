# Memory Synthesis

## Current Scope

Feature 010 is a composer-dependencies + admin-menu-migration feature. Three concrete work-item groups: (1) `composer.json` edits — bump PHP baseline `>=7.4` → `>=8.1`, bump `automattic/jetpack-autoloader ^3.0` → `^5.0`, add `wpboilerplate/wpb-access-control ^2.0.0` + `berlindb/core ^3.0.0` + `acrossai-co/main-menu ^0.0.8`; (2) atomic PHP version sync across composer.json + plugin header + README.txt + constitution + copilot-instructions; (3) migrate `admin/Partials/Menu.php` from raw `add_menu_page`/`add_submenu_page` to `\AcrossAI_Co\MainMenu\...` API while preserving the URL slug `acrossai_mcp_manager`. Zero new REST routes, no DB tables, no forms, no user input, no consent surface changes. Existing `class_exists('\WPBoilerplate\AccessControl\AccessControlManager')` guards preserved as defense-in-depth. Custom `Includes\Database\...\Query` classes NOT refactored — deferred to Feature 011.

## Relevant Decisions

- **D5 — PHPCS baseline exceptions** (`docs/memory/DECISIONS.md`, Active). Reason: Feature 010 edits `composer.json` + `Menu.php` + `AdminPageSlugs.php`. Must NOT introduce new PHPCS exclusions. Existing `phpcs.xml.dist` baseline (WP.Files.FileName.NotHyphenatedLowercase, PSR12.Files.FileHeader.IncorrectOrder, etc.) must remain intact.
- **D13 — Constitution-level formalization vs. Accepted Deviation** (Feature-007, 2026-06-30). Reason: Feature 010 updates the constitution's tech-stack section per FR-012 — that's documentation of package pins, NOT a §I–§VII principle change. No governance escalation trigger; treat as routine spec-driven doc update.
- **D14 — Cross-phase state observation via public-static predicate on owning module** (Feature-008, 2026-07-01). Reason: TASK-1 must identify whether `acrossai-co/main-menu` publishes public-static predicates (`\AcrossAI_Co\MainMenu\Registry::is_plugin_screen(...)` or similar). If it does, `admin/Main.php` asset-enqueue guard could consume the predicate INSTEAD of duplicating screen-ID checks — matches D14's precedent. If the package does NOT publish predicates, `AdminPageSlugs::plugin_screen_ids()` remains the canonical whitelist per A9.

## Active Architecture Constraints

- **A1 — All hook registration in `Main.php` via Loader** (`docs/memory/ARCHITECTURE.md`). Reason: `admin_menu` action registration is the load-bearing hook here. Nuance for Feature 010: A1 governs THIS plugin's code, not `acrossai-co/main-menu` package internals. If the package auto-hooks `admin_menu` internally (TASK-1 discovery), we REMOVE our own Loader entry for `Menu.php` in `define_admin_hooks()` — that's compliant with A1 (we no longer wire the hook; the package does). If the package expects manual hook wiring, we UPDATE the Loader entry to point at the correct callback. Zero direct `add_action`/`add_filter` calls in `admin/Partials/Menu.php` post-migration.
- **A6 — `use` imports in `Includes\*` / `Public\*` / `Admin\*`** (`docs/memory/ARCHITECTURE.md`). Reason: `Menu.php` migration adds a `use \AcrossAI_Co\MainMenu\...` import. Must be proper `use` statement at top of file, not bare FQN — prevents B1 namespace-relative-path bug.
- **A9 — Shared constants in `includes/Utilities/`** when read by ≥2 modules (`docs/memory/ARCHITECTURE.md`). Reason: `AdminPageSlugs::plugin_screen_ids()` IS the canonical whitelist consumed by `admin/Main.php::enqueue_styles/scripts` for the asset-enqueue guard (Phase 8). Feature 010 must NOT break this. If `acrossai-co/main-menu` changes the screen ID prefix (`toplevel_page_...` shape), `AdminPageSlugs::plugin_screen_ids()` MUST be extended ADDITIVELY (existing IDs preserved) — this is the FR-022 requirement.

## Accepted Deviations

- **DEV3 — Bidirectional Phase 6 ↔ Phase 7 coupling** (`docs/memory/INDEX.md`, 2026-06-30). Reason: NOT triggered by Feature 010. But documenting for context — Feature 010 must not create parallel bidirectional coupling by having Menu.php import multiple external modules or having external modules import Menu.php. Menu.php is a leaf consumer of `\AcrossAI_Co\MainMenu\...` and (indirectly, through class_exists guards) `\WPBoilerplate\AccessControl\...`. Clean unidirectional dep tree.

## Relevant Security Constraints

- **S9 — Consent-surface displayed-state from authoritative store** (`docs/memory/PROJECT_CONTEXT.md`, 2026-06-30). Reason: NOT directly triggered by Feature 010 (no consent surface rendering). Preserved as an invariant — Feature 010 does not touch `FrontendAuth::handle_cli_auth` or `ClaudeConnectors::render_authorize_page`. The Consent-surface exception in Constitution §III (added Feature-007) remains untouched.
- **§III `class_exists()` guard preservation** (spec FR-025 / CONSTRAINT 1). Reason: The 4 existing guards for `\WPBoilerplate\AccessControl\AccessControlManager` in Main.php, CliController.php, Settings.php, Menu.php are load-bearing defense-in-depth. Even though the package becomes a hard require, the guards stay. Removal is a separate feature after 3+ months soak.

## Related Historical Lessons

- **B11 — Defensive triple-check on structured reads** (`docs/memory/BUGS.md`, Feature-006, generalized Feature-008 to `.asset.php`). Reason: `vendor/autoload_packages.php` and `vendor/composer/jetpack_autoload_classmap.php` are `require`-returned manifests. Same defensive-read pattern applies IF any plugin code consumes them at runtime beyond bootstrap. Feature 010 currently touches these only through `composer dump-autoload -o` regeneration — no application-code consumption. Pattern noted for future work.
- **B12 — `wp_enqueue_scripts` non-firing on `template_redirect` exit** (`docs/memory/BUGS.md`, Feature-007). Reason: NOT applicable to Feature 010 — no `template_redirect` handlers touched. Admin menu registration fires on `admin_menu` action which fires reliably in admin context.

## Conflict Warnings

**Soft conflict — A1 nuance for third-party menu package**: If `acrossai-co/main-menu` internally calls `add_action('admin_menu', ...)`, that's INSIDE the package's namespace, not our plugin's. Our plugin's A1 compliance is measured by `grep -rn "add_action\|add_filter" admin/Partials/Menu.php` returning zero matches AND `includes/Main.php::define_admin_hooks()` not double-wiring the same hook. TASK-1 output determines the exact wiring shape. Both outcomes (remove Loader entry vs. keep and update) are A1-compliant.

**No hard conflicts.** Feature 010 aligns with Constitution §I–§VII. §III surfaces are structurally null this feature (no forms/REST/DB/transient). The Consent-surface exception amendment is untouched. Prior-feature invariants (Feature-008 admin asset guard, Feature-009 MCP boot) are preserved per FR-022 + `Cross-feature invariant` note in spec §Dependencies.

## Retrieval Notes

- **Read**: `.specify/extensions/memory-md/config.yml` (optimizer disabled → markdown flow), `docs/memory/INDEX.md` (77 lines, entire — includes D14 from Feature 008), Feature 010 `spec.md` §Context + §Requirements + §Dependencies + §Non-Goals.
- **Selected**: 3 decisions (D5, D13, D14), 3 architecture constraints (A1, A6, A9), 1 accepted deviation (DEV3, context-only), 2 security constraints (S9, §III guard preservation), 2 bug patterns (B11, B12). Total: 11 entries. Under budget (5/5/3/3/3 max = 19).
- **Not read** (below budget): `DECISIONS.md`, `ARCHITECTURE.md`, `BUGS.md`, `PROJECT_CONTEXT.md` source sections. INDEX.md summary rows sufficient for 11 selected entries.
- **Full memory read**: NOT performed. Budget respected.
- **Phase-aware**: Specify/Plan mode → prioritized boundary definitions (A1/A6/A9 module ownership) + architectural drift risks (A1 nuance for third-party auto-hook + D14 predicate publishing opportunity + A9 whitelist preservation).
- **Word count**: ~890 words. Within 900-word budget.
