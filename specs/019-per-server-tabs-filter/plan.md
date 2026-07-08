# Implementation Plan: Third-party filter for per-server tabs

**Branch**: `019-per-server-tabs-filter` | **Date**: 2026-07-08 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `specs/019-per-server-tabs-filter/spec.md`

## Summary

Add a WordPress filter `acrossai_mcp_manager_server_tabs` that lets companion plugins add, remove, reorder, or re-gate tabs on the Edit MCP Server page. The extension surface is array-based (mirrors vendor `acrossai_settings_tabs`), so third-party authors do NOT need to load `\AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\AbstractServerTab`. Internally the filter output is normalized (via a loop patterned on vendor `TabbedPageRenderer::resolve_tabs()`), deduped (first-registration wins, built-ins seeded first), sorted by priority, and hydrated back into `AbstractServerTab[]` — built-ins use their existing class instances, third-party entries are wrapped in a new `FilteredServerTab` adapter that catches `\Throwable` from the callback so a broken companion plugin cannot white-screen the page.

**Backwards compatibility is total**: `Registry::all_tabs()`, `Registry::visible_tabs()`, `Registry::render()`, `AbstractServerTab::slug()/label()/visible_for()/render()`, and every concrete tab class keep their current signatures and slug / label / visible_for behavior. The only public-API addition is `AbstractServerTab::priority(): int` (non-abstract, default 100) with slot overrides on the ten built-ins.

## Technical Context

**Language/Version**: PHP 8.1+ (per `composer.json::require.php`); no JS changes; no build step.
**Primary Dependencies**: `automattic/jetpack-autoloader ^5.0` (existing), `acrossai-co/main-menu 0.0.13` (existing — used as the reference implementation for the normalization loop, not as a runtime dependency of this filter). No new dependencies.
**Storage**: No schema changes. No new WordPress options. Filter state is purely per-request.
**Testing**: PHPUnit via `composer test` for `RegistryTest` extensions and the new `FilteredServerTabTest`. No JS tests — the extension surface is PHP-only.
**Target Platform**: WordPress 6.9+ single-site primary; multisite unchanged.
**Project Type**: WordPress plugin (PHP + SCSS build).
**Performance Goals**: Filter fires exactly once per Edit MCP Server request. Normalization is O(N) over `N = built-ins + third-party entries` — expected N ≤ 15 in practice. Sort is O(N log N). Both are dwarfed by the existing DB query for the server row. No caching layer needed.
**Constraints**:
- MUST NOT change slug/label/`visible_for()` of any built-in tab class.
- MUST NOT change `Registry::all_tabs()` / `visible_tabs()` / `render()` signatures.
- MUST NOT change `SettingsRenderer::render_tab_nav()` — URL scheme preservation is non-negotiable.
- MUST NOT allow a third-party entry to clobber a built-in slug — first-registration wins, built-ins seeded first.
- MUST NOT propagate a `\Throwable` from a third-party callback to the outer request.
- Grep audit — `apply_filters( 'acrossai_mcp_manager_server_tabs'` appears exactly once under `admin/`.
**Scale/Scope**: 12 files modified / created — 1 abstract class + 10 concrete tab classes touched by a single-line `priority()` override each, 1 refactored Registry, 1 new `FilteredServerTab`, 1 extended `RegistryTest`, 1 new `FilteredServerTabTest`, 1 new extension doc. ~60 LOC of PHP change on the production side, ~200 LOC of tests, ~150 LOC of docs.

## Constitution Check

| Principle | Verdict | Notes |
|---|---|---|
| **I. Modular Architecture** | PASS | Preserves the Feature 013 class hierarchy. `FilteredServerTab` is a thin adapter that extends `AbstractServerTab`. Registry gains three private helpers (`builtin_entries`, `normalize_entries`, `hydrate`) and one new public method (`for_server`) — module boundaries unchanged. |
| **II. WordPress Standards** | PASS | Filter naming follows `<plugin_prefix>_<subject>_<verb>` convention. PHPCS + PHPStan L8 gates apply per DoD. `_doing_it_wrong` used for malformed entries under `WP_DEBUG` — idiomatic WP. |
| **III. Security First** | PASS | Capability check runs BEFORE `visible_callback` and BEFORE `render_callback` — no third-party code executes for a user who lacks `manage_options`. `sanitize_key()` on slugs. `esc_html()` on labels at render time via existing `SettingsRenderer::render_tab_nav()`. `try/catch \Throwable` prevents white-screens. No new user input surface. |
| **IV. User-Centric Design** | PASS | Zero visible change for sites without companion plugins. Companion plugins get a documented, standard-WP-filter extension surface (`docs/extending-per-server-tabs.md`) mirroring the vendor's Settings tab filter — no new mental model. |
| **V. Extensibility** | STRENGTHENS | This IS the extensibility improvement. Prior state: no seam. New state: filter parity with vendor `acrossai_settings_tabs`. Companion plugins can now write per-server-tab code without monkey-patching. Aligned with the memory note added post-implement — `DEC-SERVER-TAB-CLASS-HIERARCHY` is SUPPLEMENTED, not superseded (built-in classes remain authoritative). |
| **VI. Reusability & DRY** | PASS | Normalization loop is copied structurally from vendor `TabbedPageRenderer::resolve_tabs()` — the plugin does not depend on the vendor class at runtime, but the algorithm shape is deliberate parity. Prevents future divergence in behavior between the two extension surfaces. |
| **VII. Definition of Done** | PASS | All 10 DoD gates apply and are enumerated in `tasks.md` §Verification. New tests exist for every FR. Extension author doc gives a copy-paste example. |

**GATE VERDICT: PASS.** No hard violations. One memory-hygiene follow-up: annotate `DEC-SERVER-TAB-CLASS-HIERARCHY` with a "SUPPLEMENTED by Feature 019" note post-implementation via `/speckit-memory-md-capture-from-diff`.

## Project Structure

### Documentation (this feature)

```text
specs/019-per-server-tabs-filter/
├── plan.md                    # This file
├── spec.md                    # Feature specification
├── tasks.md                   # Task breakdown
└── quickstart.md              # Companion-plugin author quickstart
```

### Source Code (repository root)

```text
acrossai-mcp-manager/
├── admin/Partials/ServerTabs/
│   ├── AbstractServerTab.php      # [EDIT] add priority(): int with default 100
│   ├── Registry.php               # [EDIT] add for_server() / builtin_entries() / normalize_entries() / hydrate(); refactor visible_tabs() and render() to delegate
│   ├── FilteredServerTab.php      # [NEW] adapter wrapping a filter-contributed entry array
│   ├── OverviewTab.php            # [EDIT] priority() => 10
│   ├── NpmTab.php                 # [EDIT] priority() => 20
│   ├── ClientsTab.php             # [EDIT] priority() => 30
│   ├── WpCliTab.php               # [EDIT] priority() => 40
│   ├── ToolsTab.php               # [EDIT] priority() => 50
│   ├── AbilitiesTab.php           # [EDIT] priority() => 60
│   ├── AccessControlTab.php       # [EDIT] priority() => 70
│   ├── McpTrackerTab.php          # [EDIT] priority() => 80
│   ├── UpdateServerTab.php        # [EDIT] priority() => 90
│   └── DangerZoneTab.php          # [EDIT] priority() => 100
│
├── tests/phpunit/Admin/ServerTabs/
│   ├── RegistryTest.php           # [EDIT] add 7 new cases: filter fires, add-a-tab, remove-a-tab, reorder, malformed drop, duplicate slug, throw isolation
│   └── FilteredServerTabTest.php  # [NEW] adapter-level unit tests
│
└── docs/
    └── extending-per-server-tabs.md  # [NEW] companion-plugin author guide
```

**Structure Decision**: No directory changes. Everything is co-located with the existing per-server tab code so future maintainers find the extension surface next to the built-ins they're extending.

## Complexity Tracking

Zero hard violations. The only conditional in the plan is the `_builtin` flag on entries — used to route hydration back to the existing built-in class instances (instead of wrapping every entry in a `FilteredServerTab`). This flag is internal and never exposed to third-party callbacks; documented inline in `Registry::builtin_entries()` and `Registry::hydrate()`. The trade-off (one extra internal key on each entry vs. two parallel arrays) favours simplicity of the normalization + hydration path — a single loop handles both built-ins and third-parties uniformly.
