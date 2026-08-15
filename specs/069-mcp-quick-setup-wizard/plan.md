# Implementation Plan: MCP Quick Setup Wizard

**Branch**: `069-mcp-quick-setup-wizard` | **Date**: 2026-08-16 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `specs/069-mcp-quick-setup-wizard/spec.md`

## Summary

A guided 5-step React admin wizard that condenses the plugin's 11-tab per-server-edit surface into a single linear configuration flow. Reachable via automatic redirect on plugin activation and via a persistent "Quick Setup for MCP" top-admin-bar chip. The wizard's step index lives in the URL (`?step=1..5|done`) so browser Back/Forward works and any step is deep-linkable. Each step's answers persist to a per-user 30-minute transient scratchpad and delegate all authoritative writes to existing plugin APIs (`MCPServerQuery`, wpb-ac `RuleQuery` via F015 wrapper, F017 abilities controller) — the wizard is strictly **additive**: no existing per-server-edit tab, REST route, or DB schema changes. Meets WCAG 2.1 AA. Emits no observability hooks (spec Q3 → intentionally silent). Version bump `0.2.10 → 0.2.11`.

## Technical Context

**Language/Version**: PHP 8.1+ (plugin baseline) · Node 18+ · JS: `@wordpress/scripts` toolchain (@babel/preset-env, mini-css-extract)
**Primary Dependencies**:
- PHP (existing, no new deps): `wpboilerplate/wpb-access-control ^3.1.0`, `wordpress/mcp-adapter ^0.5.0`, `berlindb/core ^3.0.0`, `acrossai-co/main-menu 0.0.33`, `automattic/jetpack-autoloader ^5.0`
- JS: `@wordpress/dataviews` (DataForm + DataViews per Principle IV), `@wordpress/components`, `@wordpress/element`, `@wordpress/api-fetch`, `@wordpress/url`, `@wordpress/i18n`, `@wordpress/hooks`, `@wordpress/compose` (`useCopyToClipboard`). **No generic React libs** (no react-query, redux, mobx, react-table, MUI, styled-components) per DEC-WP-DATAVIEWS-OVER-REACT.

**Storage**:
- Two transients only: site-level `acrossai_mcp_manager_quick_setup_do_redirect` (30s TTL) + per-user `acrossai_mcp_manager_quick_setup_state_{user_id}` (30-min TTL).
- All authoritative writes flow through existing tables via existing APIs. **No new BerlinDB tables**, no new wp_options.

**Testing**:
- PHP: PHPUnit — `admin` testsuite covers `RegistryTest`-style tests + adds `QuickSetupControllerTest`, `AdminBarEntryTest`, `ActivationRedirectTest`, `QuickSetupPageTest`. Existing `bootstrap-wp.php` harness (WP integration).
- JS: no dedicated Jest infra in the plugin today; spec does not require it. Manual smoke tests + axe DevTools a11y scan per SC-010.

**Target Platform**: WordPress admin (desktop-first, mobile responsive per design brief).
**Project Type**: WordPress plugin (single-project, PHP + built assets via `@wordpress/scripts`).

**Performance Goals**:
- FR-025: scratchpad write persists within **500ms** of step-leave.
- SC-001: end-to-end task time **<3 min** (first-run install → completion screen).
- Bundle size targets: ~200KB JS + ~30KB CSS (Step 1 dry weight; verify with `npm run build`).

**Constraints**:
- **Additive-only** (FR-029, FR-030): zero non-additive edits under `admin/Partials/ServerTabs/`, `includes/REST/` (except the new controller), `includes/Database/`.
- **Wizard bundle NEVER loads outside `?quick-setup=1`** (SC-007).
- **WCAG 2.1 AA** (FR-010a, SC-010): keyboard-only + focus-mgmt + ARIA live region + `role="progressbar"`.
- **Single-site only** — no multisite-network entry point (spec Assumption; permissible per Constitution §II with documented justification).

**Scale/Scope**: 2-3 servers typical / ≤20 max (spec Q4). No pagination, no search on server picker.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

Constitution v1.1.0 (Ratified 2026-05-28, Last Amended 2026-07-12). All 7 principles + 2 governance sections evaluated against this feature.

| # | Principle | Applies? | Status | Justification |
|---|---|---|---|---|
| I | Modular Architecture | Yes | ✅ PASS | Wizard is a self-contained module under `admin/Partials/QuickSetup/` (3 admin partials) + `includes/REST/QuickSetupController.php` (1 REST controller). Reads/writes flow through existing APIs — zero direct DB access, zero sibling-module coupling. Registers as a NEW admin surface, not as a 12th server-tab (verified via pre-flight grep in spec). |
| II | WordPress Standards Compliance | Yes | ✅ PASS w/ documented exception | PHP 8.1+ ✓ · WP 6.9+ ✓ · PHPCS + PHPStan L8 + ESLint gated in DoD ✓. **Multisite exception**: single-site only per spec Assumption — justified because the wizard is a per-site setup flow with no network-admin semantics; per-site admins access it independently on each site. Matches the plugin's existing multisite posture (WP list-table + settings-tab pattern). |
| III | Security First (NON-NEGOTIABLE) | Yes | ✅ PASS | Every wizard REST route: explicit boolean `permission_callback` returning `current_user_can('manage_options')` (S2); all POSTs verify `X-WP-Nonce` (S1); input sanitized at REST boundary; output escaped at PHP render; no direct DB queries (writes route through existing APIs that already use `$wpdb->prepare`); no OAuth tokens or App Passwords stored (wizard handles no credentials directly); no file uploads. **Consent-surface exception does NOT apply** — wizard is admin-only, not user consent flow. |
| IV | User-Centric Design (NON-NEGOTIABLE) | Yes | ✅ PASS | Server picker → DataViews (Step 1); server-create form + AC editor → DataForm (Steps 1/2). **DEV1 (parent menu) exception does NOT extend to the wizard** — wizard is a new admin surface added post-constitution; MUST use DataViews/DataForm. **AI Connectors card layout exception (v1.1.0) does NOT apply** — wizard's step 5 method cards are a new surface, not part of the AI Connectors tab. **DEV5 (hand-rolled per-server-edit tab forms) does NOT apply** — narrowed post-D37 to read-only + single-submit only; wizard is interactive multi-field → binds to D37 (React-first). |
| V | Extensibility Without Core Modification | Yes | ✅ PASS | Wizard is additive (FR-029/030). Optional integrations (`acrossai-abilities-manager`, `acrossai-pro`) are detected via `is_plugin_active()` with graceful degradation in every combination (spec edge cases). Uses WP filter contract for future extensibility surfaces if ever needed (currently no `do_action`s per spec Q3). |
| VI | Reusability & DRY | Yes | ✅ PASS | Access Control editor is REUSED from `AccessControlTab` (Step 2) — extract shared `<AccessControlEditor>` component if not already exported; wizard MUST NOT copy-paste the form. Abilities enable-all reuses F017's REST endpoint. Connection-method DTOs read from F035 `ConnectionMethodRegistry` — no reimplementation of client/npm/connector lookups. `npm run validate-packages` gated in DoD. Tier-1 (@wordpress/*) packages only — no Tier-2/Tier-3 introduction. |
| VII | Definition of Done | Yes | ✅ PASS at plan-time (11 gates carried into spec's DoD section) | PHPCS + PHPStan L8 + ESLint + PHPUnit + security review + DataForm/DataViews + no DRY dup + `acrossai_mcp_` prefix on all new symbols + `validate-packages` — all gated. **Exception clause "MCP Manager parent menu; AI Connectors tab card layout"** does not extend to wizard (see IV above). |

### Constitution-adjacent memory guidance (from memory-synthesis.md)

- **D37 DEC-ADMIN-UI-REACT-FIRST** — binding: wizard MUST use React + REST per F017/F020/F037 canonical pattern. No hand-rolled forms + vanilla JS. ✅ SATISFIED
- **DEC-WP-DATAVIEWS-OVER-REACT** — binding: no generic React libs. ✅ SATISFIED
- **D38 DEC-REUSABLE-PRIMITIVE-REGISTER-EXCEPTION** — advisory: mirror `AbstractReactMountServerTab`'s asset-enqueue shape (asset.php manifest + `wp_localize_script` + gated enqueue). ✅ ADOPTED in Phase 1
- **DEC-ACCESS-CONTROL-V2-ADOPTION** — binding: Step 2 MUST route through F015 wrapper, never `RuleQuery` directly. ✅ SATISFIED
- **DEC-ABILITY-OVERRIDE-RESOLUTION** — binding: Step 3 "Enable all" MUST call F017 controller/resolver. ✅ SATISFIED

### Soft conflicts from memory (plan-phase resolution)

- **B22 (WordPress package externals map)**: Verified in Phase 0 research — `@wordpress/dataviews` is present in `@wordpress/scripts` externals since v27.0 (WP 6.5+). WP 6.9+ target is safe. No runtime-lookup fallback needed. See `research.md` §R2.
- **B14 (activation hook priority)**: Phase 1 places `set_transient` inside `acrossai_mcp_manager_activate()` (the existing plugin activation callback, already at priority 10) — but AFTER the existing vendor-autoload guard at priority 1 has passed. If autoload fails, the P1 guard `wp_die`s before the transient set runs, so the wizard never redirects on a broken install. Documented as Phase 1 decision D-Q1 in `research.md`. Belt-and-suspenders `function_exists('set_transient')` check inside the activation callback per contract file.

### Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| (none — zero constitution violations) | — | — |

**GATE: PASS.** Proceed to Phase 0.

## Project Structure

### Documentation (this feature)

```text
specs/069-mcp-quick-setup-wizard/
├── spec.md                     # (existing) Product spec — WHAT / WHY
├── memory-synthesis.md         # (existing) Retrieval-ranked memory context
├── checklists/
│   └── requirements.md         # (existing) Spec quality checklist
├── plan.md                     # This file — technical HOW
├── research.md                 # Phase 0 output — 7 key decisions with rationale
├── data-model.md               # Phase 1 — 5 entities × shape + lifecycle
├── contracts/
│   ├── quick-setup-rest.md     # 3 REST route contracts
│   ├── react-router.md         # URL param contract for step/method
│   └── wizard-state.md         # Client-side state store shape
├── quickstart.md               # Phase 1 — dev-facing runbook
└── tasks.md                    # Phase 2 output (created by /speckit-tasks, NOT here)
```

### Source Code (WordPress plugin repository root)

```text
acrossai-mcp-manager/
├── acrossai-mcp-manager.php               # (delta) Extend acrossai_mcp_manager_activate() → set do-redirect transient at priority 10 (after P1 vendor-autoload guard)
├── admin/
│   ├── Main.php                            # (delta) enqueue_scripts()/enqueue_styles() gate quick-setup bundle on ?quick-setup=1
│   └── Partials/
│       ├── Settings.php                    # (delta) route hijack in render dispatcher — quick-setup=1 → QuickSetupPage
│       └── QuickSetup/                     # NEW dir
│           ├── QuickSetupPage.php          # NEW — singleton; hijacks plugin page render; emits React mount div
│           ├── AdminBarEntry.php           # NEW — singleton; admin_bar_menu @ priority 100
│           └── ActivationRedirect.php      # NEW — singleton; admin_init @ priority 5; consumes do-redirect transient, calls wp_safe_redirect
├── includes/
│   ├── Main.php                            # (delta) wire 4 new admin/REST classes in define_admin_hooks() / define_public_hooks()
│   └── REST/
│       └── QuickSetupController.php        # NEW — singleton; 3 routes under /acrossai-mcp-manager/v1/quick-setup/*
├── src/
│   ├── js/
│   │   ├── quick-setup.js                  # NEW entry point — mounts <App /> at #acrossai-mcp-quick-setup-root
│   │   └── quick-setup/                    # NEW dir (component tree)
│   │       ├── App.jsx                     # top-level; router + state hydration + StepLayout wrap
│   │       ├── StepLayout.jsx              # header + progress bar (role="progressbar" + aria-live) + footer
│   │       ├── hooks/
│   │       │   ├── useWizardRouter.js      # ?step + ?method → { step, method, goTo, advance, back, exit }; popstate listener
│   │       │   └── useWizardState.js       # REST hydration + saveStep + optimistic update; useReducer + Context
│   │       ├── components/
│   │       │   ├── CodeBlock.jsx           # inline + pane variants; useCopyToClipboard
│   │       │   ├── Notice.jsx              # left-color-bar; status='info'|'warning'|'success'|'error'
│   │       │   ├── RadioCard.jsx           # server row / method card wrapper
│   │       │   └── icons.jsx               # inline SVGs (chain, puzzle, terminal, check, chevrons)
│   │       └── steps/
│   │           ├── Step1_ServerPick.jsx    # DataViews list + Create button
│   │           ├── Step1_ServerCreate.jsx  # DataForm inline create
│   │           ├── Step2_AccessControl.jsx # Notice + reused <AccessControlEditor server_id={id} />
│   │           ├── Step3_Abilities.jsx     # dual state (Manager missing vs active)
│   │           ├── Step4_EnableServer.jsx  # yellow notice + toggle (auto-skip when already enabled)
│   │           ├── Step5_MethodGrid.jsx    # 2×2 grid + tri-state Connectors card
│   │           ├── Step5_ConnectorsPanel.jsx
│   │           ├── Step5_ClientPanel.jsx   # pill row + JSON <CodeBlock> per client
│   │           ├── Step5_NpmPanel.jsx      # npx command <CodeBlock>
│   │           ├── Step5_WpCliPanel.jsx    # wp mcp-adapter serve <CodeBlock>
│   │           └── Completion.jsx          # summary + 3 CTAs
│   ├── scss/
│   │   └── quick-setup.scss                # NEW — design tokens as SCSS vars; BEM-lite classes; scoped under .acrossai-mcp-quick-setup-wrap
│   └── (existing js/scss entries untouched)
├── assets/
│   └── quick-setup/                        # NEW dir
│       └── acrossai-logo.svg               # copy from Claude Design project
├── webpack.config.js                       # (delta) add 'js/quick-setup' entry alongside js/embeds, js/abilities, js/tools
├── tests/phpunit/
│   ├── REST/
│   │   └── QuickSetupControllerTest.php    # NEW — 3 routes × 3 branches each (permission + happy path + error)
│   └── Admin/QuickSetup/
│       ├── AdminBarEntryTest.php           # NEW — visibility gate on manage_options
│       ├── ActivationRedirectTest.php      # NEW — transient set/consume; bulk-activation guard; network-activation guard
│       └── QuickSetupPageTest.php          # NEW — render hijack when quick-setup=1 present
└── README.txt                              # (delta at Phase 2) — new = 0.2.11 = changelog section + Stable tag bump
```

**Structure Decision**: **WordPress plugin single-project layout**. Documentation lives under `specs/069-mcp-quick-setup-wizard/`; source under the standard plugin dirs (`admin/`, `includes/`, `src/`, `assets/`, `tests/`). No monorepo split — the wizard is a self-contained feature of the existing plugin. React tree lives under `src/js/quick-setup/` mirroring the F017/F020/F037 pattern (single entry per feature, sub-components colocated). SCSS lives under `src/scss/` per plugin convention. Assets (SVG logo) live under a new `assets/quick-setup/` dir to keep the design source-of-truth close to the feature and out of the shared `admin/`/`public/` chrome.

---

## Phase 0 output: [research.md](./research.md) — 7 key decisions resolved
## Phase 1 output: [data-model.md](./data-model.md) — 5 entities
## Phase 1 output: [contracts/](./contracts/) — 3 REST + 1 router + 1 state contract
## Phase 1 output: [quickstart.md](./quickstart.md) — dev-facing runbook
