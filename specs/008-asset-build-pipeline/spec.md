# Feature Specification: Asset Build Pipeline — CSS + JS via @wordpress/scripts

**Feature Number**: 008
**Feature Branch**: `008-asset-build-pipeline`
**Created**: 2026-07-01
**Status**: Draft
**Input**: User description (Phase 8 from `docs/planings-tasks/README.md`): "Asset Build Pipeline — CSS + JS via @wordpress/scripts"

---

## Context

Phase 8 closes the migration from the v0.0.4 flat `assets/` layout (in the SOURCE repo `acrossai-mcp-manager/`) to the `@wordpress/scripts` build pipeline (in this TARGET repo). Phases 1–7 shipped all PHP module migrations; asset migration proceeded ad-hoc during those phases and is INCOMPLETE:

- `webpack.config.js` currently has 4 entries (`js/frontend`, `js/backend`, `css/frontend`, `css/backend`) — MISSING `css/frontend-oauth`.
- `src/scss/{backend,frontend}.scss` + `src/js/{backend,frontend}.js` exist — content parity vs. v0.0.4 unverified.
- `src/scss/frontend-oauth.scss` DOES NOT EXIST.
- `admin/Main.php::enqueue_styles/scripts` — correctly guards on plugin admin screen ID whitelist + reads from `build/{css,js}/backend.asset.php` (already Phase-8-compliant per its Phase 2 implementation).
- `public/Main.php::enqueue_styles/scripts` — **UNGUARDED** — enqueues `frontend.css` + `frontend.js` on EVERY front-end page load. This is a measurable asset leak that Phase 8 MUST close.
- Phase 7 `Public\Partials\FrontendAuth::enqueue_assets()` already enqueues its own `acrossai-mcp-frontend` handle, scoped to the CLI consent virtual page. Phase 8 must reconcile the two enqueue paths so a request to the consent surface does not double-register nor conflict.
- OAuth consent surface (Phase 5) currently has no dedicated CSS bundle enqueued — either styles are inline, absent, or bundled into the shared frontend handle. Phase 8 introduces the dedicated `frontend-oauth` handle.

This is a migration-finalization phase — no new business features, purely infrastructure closure.

---

## User Scenarios & Testing *(mandatory)*

### User Story 1 — Developer Builds Assets Locally and All Entries Produce Cache-Bust Manifests (Priority: P1)

A developer clones the repo, runs `npm install && npm run build`, and expects all 5 asset entries (2 JS + 3 CSS) to build cleanly. Each build output is accompanied by a versioned `*.asset.php` manifest with the `['dependencies' => array, 'version' => hash]` shape that WordPress's `wp_enqueue_*` functions consume for cache-busting.

**Why this priority**: Without a clean build, no downstream story works. Foundational gate for shipping.

**Independent Test**: `rm -rf build/ node_modules/ && npm install && npm run build` completes with exit code 0. Then `ls build/css/*.asset.php build/js/*.asset.php | wc -l` returns exactly 5.

**Acceptance Scenarios**:

1. **Given** a clean working tree with `node_modules/` installed, **When** `npm run build` runs, **Then** the exit code is 0 and `build/css/{backend,frontend,frontend-oauth}.css` + `build/css/{backend,frontend,frontend-oauth}-rtl.css` + `build/js/{backend,frontend}.js` all exist alongside their `.asset.php` sibling manifests.
2. **Given** `npm run build` has just completed, **When** each `build/{css,js}/*.asset.php` is `require`d, **Then** it returns an `array{dependencies: string[], version: string}` shape (`version` a non-empty string, `dependencies` possibly empty but always an array).
3. **Given** the `src/media/` directory contains binary assets, **When** `npm run build` runs, **Then** the CopyPlugin (already configured) copies them to `build/media/`.

---

### User Story 2 — Admin Assets Enqueue Only on the MCP Manager Screens (Priority: P1)

A site administrator navigates to any WordPress admin screen. Only the MCP Manager plugin's own admin screens load `build/css/backend.css` and `build/js/backend.js`. On every other admin screen (Dashboard, Posts, Pages, Media, Comments, Users, etc.), the plugin's admin assets are absent from the page HTML.

**Why this priority**: Global admin-asset leak wastes bandwidth on every admin page load and can style-collide with other admin screens. Well-known WP hygiene requirement.

**Independent Test**: `curl -b <admin-cookies> https://example.com/wp-admin/index.php | grep -c 'acrossai-mcp'` returns `0`. `curl -b <admin-cookies> https://example.com/wp-admin/admin.php?page=acrossai_mcp_manager | grep -c 'build/css/backend.css'` returns `≥1`.

**Acceptance Scenarios**:

1. **Given** a logged-in administrator, **When** they visit any admin screen NOT in the plugin's whitelisted screen ID set, **Then** the response HTML contains zero references to the plugin's asset build outputs.
2. **Given** the same administrator, **When** they visit any of the plugin's whitelisted admin screens, **Then** the response HTML contains exactly one `<link rel="stylesheet">` for the backend CSS handle AND one `<script>` for the backend JS handle, each with a `?ver=<hash>` query string sourced from the `.asset.php` manifest.
3. **Given** a subscriber-role user (without `manage_options`), **When** they access the front-end, **Then** they never see admin-scoped asset handles in the HTML (defense-in-depth against admin-scope inference via asset URLs).

---

### User Story 3 — Frontend Assets Enqueue Only on Their Virtual Consent Pages (Priority: P1)

A visitor loads any front-end page of the site. The plugin's `build/css/frontend.css` and `build/css/frontend-oauth.css` handles are absent from the HTML unless the visitor is on the CLI consent surface (`/acrossai-mcp-manager/`) or the OAuth consent surface (Phase 5 route) respectively. On the site's home page, blog posts, taxonomy archives, and Search results, none of the plugin's frontend assets load.

**Why this priority**: Currently `public/Main.php::enqueue_styles/scripts` runs UNGUARDED — a measurable regression that grew during Phase 5–7 development. Phase 8 closes it as the migration's last hardening step. Without this, every visitor pays the cost of unused CSS.

**Independent Test**: `curl -o /dev/null -s https://example.com/ && curl https://example.com/ | grep -c 'acrossai-mcp'` returns `0`. Then `curl -b <cookies> 'https://example.com/acrossai-mcp-manager/?action=cli_auth&code=x&server=x' | grep -c 'acrossai-mcp-frontend-css'` returns `≥1`.

**Acceptance Scenarios**:

1. **Given** an anonymous visitor to the site's home page (`/`), **When** the response HTML is inspected, **Then** it contains zero references to any of the plugin's build outputs (backend.*, frontend.*, frontend-oauth.*).
2. **Given** the same anonymous visitor to any published post URL, **When** the response is inspected, **Then** same as above — zero plugin-asset references.
3. **Given** a logged-in visitor to the CLI consent virtual page (`/acrossai-mcp-manager/?action=cli_auth&…`), **When** the response is inspected, **Then** the CLI consent's `acrossai-mcp-frontend` handle IS present (Phase 7 behavior, unchanged) AND `frontend-oauth` handle is NOT present.
4. **Given** a logged-in visitor to the OAuth consent page (Phase 5 route), **When** the response is inspected, **Then** the `frontend-oauth` handle IS present.
5. **Given** both virtual consent pages loaded in one browser session, **When** each enqueue path runs, **Then** no double-registration occurs — `wp_enqueue_style` is idempotent per WP core; subsequent calls with the same handle no-op.

---

### User Story 4 — Content Parity With v0.0.4 Source Assets (Priority: P1)

A reviewer diffs each `src/scss/*.scss` and `src/js/*.js` file against the equivalent v0.0.4 `assets/*` source file (in the READ-ONLY source repo). Every visual rule and every JavaScript behavior from the v0.0.4 plugin is preserved in the migrated source. No CSS class, animation, media query, or JS event handler is dropped during the migration.

**Why this priority**: The migration's promise is "zero regression". Missed CSS rules produce broken layouts; missed JS produces broken interactions. This story ensures the promise holds for asset content specifically.

**Independent Test**: For each `src/scss/*.scss` file, extract all CSS selectors and compare against the selectors in the equivalent v0.0.4 `assets/*.css` file. Every v0.0.4 selector MUST appear (verbatim or in an equivalent SCSS nested form) in the migrated source. Same for JS event handlers in `src/js/backend.js` vs `assets/admin.js`.

**Acceptance Scenarios**:

1. **Given** v0.0.4 `assets/admin.css`, **When** its selectors are diffed against `src/scss/backend.scss` (rendered flat), **Then** every non-comment selector present in v0.0.4 is also present in the migration.
2. **Given** v0.0.4 `assets/frontend-auth.css`, **When** diffed against `src/scss/frontend.scss`, **Then** same parity holds.
3. **Given** v0.0.4 `assets/frontend-oauth.css`, **When** diffed against the newly-created `src/scss/frontend-oauth.scss`, **Then** same parity holds.
4. **Given** v0.0.4 `assets/admin.js`, **When** diffed against `src/js/backend.js`, **Then** every top-level function, event handler, and jQuery `on()`/`ready()` binding is preserved.
5. **Given** v0.0.4 has no `assets/frontend.js`, **When** `src/js/frontend.js` is inspected, **Then** it MAY contain minimal boilerplate (empty module wrapper) since Phase 8 does not introduce new frontend JS — the file exists only to satisfy the webpack entry map. This is documented as an intentional stub.

---

### User Story 5 — Legacy `assets/` Directory Absent From Target Repo (Priority: P2)

A reviewer runs `ls acrossai-mcp-manager-new/assets/` and receives "No such file or directory". No stale copies of the old flat asset layout remain in the target tree. All asset content lives in `src/scss/`, `src/js/`, or `src/media/`.

**Why this priority**: Housekeeping — prevents future developers from confusing the source-of-truth (build inputs are `src/*`, build outputs are `build/*`, and there is no third location).

**Independent Test**: `find /path/to/acrossai-mcp-manager-new -maxdepth 2 -type d -name assets` returns zero results.

**Acceptance Scenarios**:

1. **Given** the target repo root, **When** `assets/` is checked, **Then** it does not exist. (Currently already true — this story is a verification, not a deletion. Included so any future re-emergence gets caught.)
2. **Given** the source repo's v0.0.4 `assets/` directory, **When** its content has been ported to `src/*`, **Then** the source repo is left untouched (source is read-only).

---

### Edge Cases

- **`npm run build` fails on a machine without matching Node version**: `package.json`'s `engines` field expresses the requirement; the build's own error is authoritative for troubleshooting. Out of scope for this phase.
- **A v0.0.4 CSS file uses a syntax SCSS rejects** (e.g. bare `@import` chains): the migration MUST rewrite these to SCSS-compatible `@use` / `@forward` — content parity is preserved, syntax is upgraded.
- **A v0.0.4 CSS file references an image path that doesn't exist in `src/media/`**: the referenced binary must be copied to `src/media/` (already handled by CopyPlugin for whole-directory sync).
- **The webpack manifest emits an unexpected extra entry**: the version-fallback in the enqueue reader must gracefully handle non-conforming shapes without warnings.
- **Someone re-introduces an `assets/` directory at target root**: US5's verification (via CI grep or `find`) should catch it in a subsequent PR.
- **Two consent pages loaded in a single browser session** (CLI then OAuth via a new tab): each enqueue path is idempotent via `wp_enqueue_style` — subsequent calls with the same handle no-op. No double-load possible.
- **`build/*.asset.php` file is present but corrupted** (empty file, non-array return, missing keys): the enqueue reader must apply defensive triple-check (matches Phase 6 B11 pattern) and fall back to the plugin version constant, without emitting warnings.

---

## Requirements *(mandatory)*

### Functional Requirements

#### Build pipeline (webpack + `@wordpress/scripts`)

- **FR-001**: `webpack.config.js` MUST expose exactly 5 named entries in its `entry` map:
  - `js/backend` → `./src/js/backend.js`
  - `js/frontend` → `./src/js/frontend.js`
  - `css/backend` → `./src/scss/backend.scss`
  - `css/frontend` → `./src/scss/frontend.scss`
  - `css/frontend-oauth` → `./src/scss/frontend-oauth.scss`
- **FR-002**: `src/scss/frontend-oauth.scss` MUST exist (it currently does not) with content ported verbatim from v0.0.4 source `assets/frontend-oauth.css`.
- **FR-003**: `src/scss/backend.scss` MUST preserve every CSS selector, rule block, media query, and animation from v0.0.4 source `assets/admin.css` (content-parity verification).
- **FR-004**: `src/scss/frontend.scss` MUST preserve the same from v0.0.4 source `assets/frontend-auth.css`.
- **FR-005**: `src/js/backend.js` MUST preserve every top-level function, event handler, and jQuery `on()`/`ready()` binding from v0.0.4 source `assets/admin.js`.
- **FR-006**: `src/js/frontend.js` MAY contain only a minimal module stub (v0.0.4 has no `assets/frontend.js`). Its existence is required by the webpack entry map; no functional content is required this phase.
- **FR-007**: `npm run build` MUST exit with code 0 on a clean working tree with `node_modules/` freshly installed.
- **FR-008**: `npm run build` MUST emit `build/{css,js}/<name>.asset.php` for every named entry, with the array shape `['dependencies' => string[], 'version' => string]` (non-empty `version` field).
- **FR-009**: `npm run build` MUST emit `build/css/<name>-rtl.css` for every CSS entry (`@wordpress/scripts` handles this automatically via `rtlcss`).
- **FR-010**: `npm run build` MUST NOT modify any file under `src/` (build is read-only against sources).
- **FR-011**: `npm run validate-packages` MUST pass (checks package.json Tier 1 / Tier 2 hierarchy per project constitution).

#### Enqueue methods — admin

- **FR-012**: `admin/Main.php::enqueue_styles()` MUST enqueue `build/css/backend.css` ONLY when the current admin screen ID is in a documented whitelist (currently `AdminPageSlugs::plugin_screen_ids()`). No enqueue on non-plugin admin screens.
- **FR-013**: `admin/Main.php::enqueue_scripts()` MUST enqueue `build/js/backend.js` under the same screen-ID whitelist.
- **FR-014**: Both admin enqueue methods MUST read `dependencies` and `version` from `build/{css,js}/backend.asset.php` via `file_exists()`-guarded `require`. On missing or malformed manifest, MUST fall back silently (no `error_log`, no `_doing_it_wrong`) with a deterministic version fallback.
- **FR-015**: Admin enqueue methods MUST contain zero hardcoded version strings (checked via CI grep for `wp_enqueue_*` calls with literal version arguments) AND zero hardcoded dependency arrays (except a documented empty `[]` for standalone assets).
- **FR-015-A**: The `admin/Main.php` file already satisfies FR-012 through FR-015 per its Phase 2 implementation. This phase's task is VERIFICATION ONLY, not re-implementation. If PHPCS / static analysis surfaces drift from these FRs, treat as a Phase 8 bug.

#### Enqueue methods — public / frontend

- **FR-016**: `public/Main.php::enqueue_styles()` MUST guard against global enqueue. It MUST NOT enqueue any handle unless one of the following is true:
  - `get_query_var('acrossai_mcp_auth')` is truthy (CLI consent surface — currently handled by `FrontendAuth::enqueue_assets`)
  - OR the current request is on the OAuth consent surface (Phase 5 route — the exact condition to match is prescribed by Phase 5's rendering class)
- **FR-017**: When the OAuth consent surface is active, `public/Main.php::enqueue_styles()` MUST enqueue a `frontend-oauth` handle sourced from `build/css/frontend-oauth.css` with version and dependencies read from `build/css/frontend-oauth.asset.php`.
- **FR-018**: `public/Main.php::enqueue_scripts()` MUST apply the same guard as FR-016. If neither consent surface is active, MUST enqueue zero handles.
- **FR-019**: Both public enqueue methods MUST read `dependencies` and `version` from the respective `build/{css,js}/*.asset.php` via `file_exists()`-guarded `require`. Same silent-fallback semantics as FR-014.
- **FR-020**: `public/Main.php` MUST NOT re-enqueue the `acrossai-mcp-frontend` handle that Phase 7's `FrontendAuth::enqueue_assets()` already registers — either by delegating to Phase 7 for the CLI surface, or by scoping its own enqueue to OAuth-only. The exact factoring is a planning decision; the requirement is: exactly ONE call to `wp_enqueue_style('acrossai-mcp-frontend', …)` executes per request when the CLI surface is active.
- **FR-021**: `wp_style_add_data($handle, 'rtl', 'replace')` MUST be called for every enqueued CSS handle immediately after `wp_enqueue_style`, so WP auto-substitutes the RTL variant when `is_rtl()` returns true.

#### Housekeeping

- **FR-022**: The target repo root MUST NOT contain an `assets/` directory. Verified by `find . -maxdepth 1 -type d -name assets` returning empty (currently the case; Phase 8 documents this as a permanent state).
- **FR-023**: No task in this phase modifies the source repo `acrossai-mcp-manager/assets/` (the source is read-only). All content is copied INTO the target's `src/` tree.

### WordPress Requirements

| Field | Value |
|---|---|
| PHP version | 8.0+ (project constitution target) |
| WordPress version | 6.9+ |
| Multisite | Single-site only (asset pipeline is per-install) |
| Required Composer packages | None new; existing `automattic/jetpack-autoloader` remains |
| Required npm packages | `@wordpress/scripts` (existing), `webpack-remove-empty-scripts` (existing), `copy-webpack-plugin` (existing) — no new npm dep |
| Required existing PHP classes | `Includes\Utilities\AdminPageSlugs` (screen-ID whitelist), `Includes\Main::define_admin_hooks` / `::define_public_hooks` (hook wiring), Phase 7 `Public\Partials\FrontendAuth`, Phase 5 OAuth consent rendering class |

### Module Placement

| File | Namespace | Action |
|---|---|---|
| `webpack.config.js` | (build config) | **Extend** — add `css/frontend-oauth` entry to the entry map |
| `src/scss/frontend-oauth.scss` | (build source) | **New** — port content from v0.0.4 `assets/frontend-oauth.css` |
| `src/scss/backend.scss` | (build source) | **Verify content parity** with v0.0.4 `assets/admin.css` |
| `src/scss/frontend.scss` | (build source) | **Verify content parity** with v0.0.4 `assets/frontend-auth.css` |
| `src/js/backend.js` | (build source) | **Verify content parity** with v0.0.4 `assets/admin.js` |
| `src/js/frontend.js` | (build source) | **Verify** — minimal stub is acceptable |
| `admin/Main.php` | `AcrossAI_MCP_Manager\Admin` | **Verify only** — Phase 2 impl already satisfies FR-012 through FR-015 |
| `public/Main.php` | `AcrossAI_MCP_Manager\Public` | **Extend** — add guards per FR-016, add `frontend-oauth` handle per FR-017, RTL data per FR-021 |
| `build/css/frontend-oauth.css` | (build artifact) | **New** — emitted by webpack; commit to repo per existing convention |
| `build/css/frontend-oauth.asset.php` | (build artifact) | **New** — same |
| `build/css/frontend-oauth-rtl.css` | (build artifact) | **New** — same, RTL variant |

**Hook Registration Rule**: All `add_action('wp_enqueue_scripts', …)` and `add_action('admin_enqueue_scripts', …)` calls MUST be wired via `Includes\Main::define_admin_hooks()` / `::define_public_hooks()`. Zero hook calls in `admin/Main.php` or `public/Main.php` constructors.

### Admin UI Requirements

This phase introduces NO admin UI. All work is build-pipeline configuration and enqueue-method changes.

### REST API Contract

This phase introduces NO REST routes.

### Database / Storage

This phase introduces NO database tables and NO options.

### Security Checklist

*(Derived from Constitution §III — verify all that apply to this feature)*

- [ ] All admin enqueue paths guard on `AdminPageSlugs::plugin_screen_ids()` — no admin asset leak to non-plugin screens (FR-012, FR-013)
- [ ] All public enqueue paths guard on the consent-surface query var or route — no frontend asset leak to home / posts / other pages (FR-016, FR-018)
- [ ] Zero hardcoded version strings in enqueue methods — cache-busting sourced from `.asset.php` manifest (FR-015)
- [ ] `file_exists()` guard around every `.asset.php` `require` — no PHP warnings emitted on missing manifest (FR-014, FR-019)
- [ ] Defensive triple-check on `.asset.php` return shape — `is_array + isset(dependencies, version) + is_string(version)` (matches B11 pattern from Phase 6)
- [ ] No `wp_enqueue_style('acrossai-mcp-manager', ...)` calls at global scope — every handle scoped to a specific surface
- [ ] RTL variant registered for every CSS handle via `wp_style_add_data($handle, 'rtl', 'replace')` (FR-021)

### Key Entities

- **Asset entry**: A named webpack entry point (5 total) that maps a source file (`src/scss/*.scss` or `src/js/*.js`) to a build output (`build/css/*.css` or `build/js/*.js`).
- **Asset manifest** (`build/{css,js}/*.asset.php`): Emitted by `@wordpress/scripts`'s dependency-extraction plugin. Shape: `array{dependencies: string[], version: string}`. Version is a content hash (cache-bust) automatically regenerated on every build.
- **Handle**: The string identifier passed to `wp_enqueue_style()` / `wp_enqueue_script()`. Each entry corresponds to one handle. Current handles: `acrossai-mcp-manager` (admin, plugin name), `acrossai-mcp-frontend` (Phase 7 CLI consent), `acrossai-mcp-frontend-oauth` (NEW — Phase 5 OAuth consent).

---

## Success Criteria *(mandatory)*

### Definition of Done Gates

All of the following MUST pass before this feature is considered complete:

- [ ] PHPCS validation: zero errors on `public/Main.php` (and zero regression on `admin/Main.php`) (`vendor/bin/phpcs`)
- [ ] PHPStan level 8: zero errors on `public/Main.php` and `admin/Main.php` (`vendor/bin/phpstan`)
- [ ] `npm run build` exits 0
- [ ] `npm run validate-packages` passes
- [ ] All 5 `build/{css,js}/*.asset.php` files exist with expected shape
- [ ] Content parity check: manual diff of each `src/*` file against v0.0.4 `assets/*` equivalent — every selector / handler preserved (FR-003, FR-004, FR-005)
- [ ] Manual admin walkthrough: on 5 non-plugin admin screens (Dashboard, Posts list, Comments, Users, Tools), no plugin asset handles appear in HTML
- [ ] Manual admin walkthrough: on 3 plugin admin screens, exactly the backend handles appear
- [ ] Manual frontend walkthrough: on 5 front-end pages (home, blog post, taxonomy, search, static page), no plugin asset handles appear in HTML
- [ ] Manual consent-surface walkthrough: CLI consent page enqueues `acrossai-mcp-frontend` only; OAuth consent page enqueues `acrossai-mcp-frontend-oauth` only
- [ ] All 4 pre-ship validation scripts (see below) exit 0

### Measurable Outcomes

- **SC-001**: `npm run build` completes with exit code 0 on a fresh `node_modules/` install.
- **SC-002**: `ls build/css/*.asset.php build/js/*.asset.php | wc -l` returns exactly 5.
- **SC-003**: `curl -b <admin-cookies>` against 5 sampled non-plugin admin URLs returns zero occurrences of any plugin asset handle (grep count == 0).
- **SC-004**: `curl -b <admin-cookies>` against the plugin's admin listing screen returns exactly 1 backend.css handle AND exactly 1 backend.js handle.
- **SC-005**: `curl` against 5 sampled front-end URLs (home, post, category, search results, static page) returns zero occurrences of any plugin asset handle.
- **SC-006**: `curl -b <cookies>` against `/acrossai-mcp-manager/?action=cli_auth&code=x&server=x` returns exactly 1 `acrossai-mcp-frontend` handle AND zero `acrossai-mcp-frontend-oauth` handle.
- **SC-007**: `curl -b <cookies>` against the OAuth consent page returns exactly 1 `acrossai-mcp-frontend-oauth` handle AND zero `acrossai-mcp-frontend` handle (from Phase 7).
- **SC-008**: `grep -rn "wp_enqueue_style.*ver=\|wp_enqueue_script.*'[0-9]\." admin/ public/` returns zero matches (no hardcoded version strings).
- **SC-009**: `find . -maxdepth 1 -type d -name assets` returns zero results (no legacy assets/ dir at target root).
- **SC-010**: All 4 pre-ship validation scripts (`validate-structure.mjs`, `validate-security.mjs`, `detect-deprecations.mjs`, `detect-rest-endpoints.mjs` under `.agents/skills/wp-plugin-development/scripts/`) exit with code 0.

---

## Assumptions

- **Partial build pipeline is pre-existing** — 4 of 5 webpack entries are already configured; `admin/Main.php` already satisfies FR-012 through FR-015 per its Phase 2 implementation; `public/Main.php` is partially implemented but UNGUARDED (the primary defect this phase closes).
- **v0.0.4 source repo is the content-parity truth** — `/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/mcp-migration/acrossai-mcp-manager/assets/` (4 files: `admin.css`, `admin.js`, `frontend-auth.css`, `frontend-oauth.css`) is authoritative for CSS/JS parity checks. The source repo is READ-ONLY per project convention.
- **OAuth consent surface identification**: the exact query var / route / class-method matcher that identifies "we are rendering the OAuth consent page" is prescribed by Phase 5's rendering class. Phase 8 consumes that predicate; it does not redefine it.
- **`@wordpress/scripts` version is stable** — no npm-side upgrade is bundled into this phase. If a future @wordpress/scripts upgrade breaks the manifest shape, that's a separate task (out of scope).
- **Build artifacts committed to repo** — existing convention (verified for Phases 1–7) is that `build/` is committed. Phase 8 follows the same convention: `build/css/frontend-oauth.*` files are committed after `npm run build`.
- **jQuery is available on both admin and public** — matching v0.0.4 behavior; Phase 8 does not enqueue jQuery separately (relies on WP core `dependencies` array in the manifest).
- **`js/frontend.js` may be an empty stub** — v0.0.4 has no `assets/frontend.js`; the webpack entry map requires `src/js/frontend.js` to exist for the emit to succeed. A stub `// Reserved for future frontend JS` is acceptable.
- **RTL variants shipped automatically by `@wordpress/scripts`** — no additional configuration needed; `wp_style_add_data($handle, 'rtl', 'replace')` in enqueue code drives WP's auto-substitution.
- **Pre-ship validation scripts are ORTHOGONAL** — they don't require Phase 8 completion to run, but Phase 8's Definition of Done includes running them as the final release gate.

---

## Dependencies

| Phase | Dependency | Status |
|---|---|---|
| Phase 1 (core boot flow) | `Includes\Main::define_admin_hooks` + `define_public_hooks` wire the enqueue actions | ✅ shipped |
| Phase 2 (admin UI) | `admin/Main.php` + `AdminPageSlugs::plugin_screen_ids()` | ✅ shipped — already satisfies admin enqueue FRs |
| Phase 5 (OAuth) | The rendering class that identifies "OAuth consent page is active" — Phase 8's public enqueue guard consumes this predicate | ✅ shipped |
| Phase 7 (Frontend CLI) | `Public\Partials\FrontendAuth::enqueue_assets()` already registers the `acrossai-mcp-frontend` handle for the CLI consent surface. Phase 8's `public/Main.php` MUST reconcile (no double-enqueue) | ✅ shipped (merged 2026-06-30 via PR #9) |

**Cross-phase constraint**: Phase 8 does NOT touch `FrontendAuth::enqueue_assets()` — that method's contract is stable. Phase 8's `public/Main.php` must NOT compete with it. The two possible reconciliation strategies (delegate to FrontendAuth vs. narrow public/Main.php to OAuth-only) are planning decisions resolved during `/speckit-plan`.

---

## Pre-Ship Validation Scripts

After all Phase 8 Definition of Done gates pass, the following 4 validation scripts run as the final release readiness check for the entire migration (Phases 1–8):

```bash
node .agents/skills/wp-plugin-development/scripts/validate-structure.mjs --dir=.
node .agents/skills/wp-plugin-development/scripts/validate-security.mjs --dir=.
node .agents/skills/wp-plugin-development/scripts/detect-deprecations.mjs --dir=.
node .agents/skills/wp-plugin-development/scripts/detect-rest-endpoints.mjs --dir=.
```

Each MUST exit with code 0. Any failure blocks the `feature/issue-3` → `main` cutover. These scripts are OUT-OF-SCOPE for the Phase 8 build-pipeline work itself but are IN-SCOPE for the Phase 8 Definition of Done (SC-010).

---

## Non-Goals (Out of Scope This Phase)

- **New CSS or JS content** — Phase 8 is a migration finalization, not a feature addition. Any new frontend behavior belongs in a subsequent phase.
- **`@wordpress/scripts` version bump** — stability first; upgrade in a separate task.
- **JS module modernization** (jQuery → vanilla, IIFE → ES modules) — v0.0.4 parity is the north star for content.
- **CSS preprocessor migration** (Sass → PostCSS or vice versa) — SCSS is fine as-is.
- **Block editor asset support** — the existing `webpack.config.js` includes `blockStylesheets()` and `blockEntries` fallbacks; those remain untouched.
- **Custom fonts pipeline** — CopyPlugin already handles `src/fonts/` if present; no work required this phase.
