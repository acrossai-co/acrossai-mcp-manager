# Feature Specification: Composer Dependencies Update — PHP 8.1 baseline + BerlinDB + Access Control + Main Menu

**Feature Number**: 010
**Feature Branch**: `010-composer-dependencies`
**Created**: 2026-07-01
**Status**: Draft
**Input**: Detailed Description block from `docs/planings-tasks/010-composer-dependencies.md` (11-task planning doc surfaced by the 2026-07-01 migration-completion audit)

---

## Context

Post-migration audit (2026-07-01) surfaced three composer-side issues that must be resolved before the `feature/issue-3 → main` cutover:

1. **PHP version mismatch** — `composer.json` declares `"php": ">=7.4"` but the plugin header + `README.txt` say `Requires PHP: 8.0`. A developer installing on PHP 7.4 gets a successful `composer install` followed by an activation failure. This feature corrects the drift AND bumps the baseline to **PHP 8.1** in all sync points.
2. **Optional-but-required packages have no composer entry** — `\WPBoilerplate\AccessControl\AccessControlManager` is consumed in 4 production files (guarded by `class_exists()`) but not declared as a require. Same story for `berlindb/core` (referenced by name only, not imported — custom Query classes mimic its shape).
3. **`acrossai-co/main-menu` migration deferred** — the plugin's admin menu (`admin/Partials/Menu.php`) still registers a top-level menu via raw WordPress `add_menu_page` / `add_submenu_page` calls. The `acrossai-co/main-menu` package (namespace `\AcrossAI_Main_Menu\`) owns the shared **`acrossai` top-level parent menu** across all AcrossAI plugins. This feature migrates our admin surface from top-level to a **submenu of the shared `acrossai` parent**, matching the pattern established by `acrossai-abilities-manager` Feature 038.

**Outcome after this feature**:
- `composer.json` reflects the actual runtime dependency graph (5 packages required)
- PHP 8.1 baseline uniformly declared (composer.json + plugin header + README.txt + constitution + copilot-instructions — atomic)
- Admin menu registered as a submenu of the shared `acrossai` parent (owned by `acrossai-co/main-menu`); the plugin's admin URL `?page=acrossai_mcp_manager` is preserved for external bookmarks and Application Password callbacks (submenu URLs behave identically to top-level URLs — `admin.php?page=<slug>` works for both)
- `wpb-access-control` becomes a hard require (but `class_exists()` guards STAY in place as defense-in-depth)
- `berlindb/core` is available in `vendor/` but the custom Query classes remain — a real-BerlinDB refactor is scoped as Feature 011

---

## Clarifications

### Session 2026-07-01

- Q: PHPUnit test coverage requirement for admin/Partials/Menu.php migration → A: Manual smoke test (SC-005 curl) + Phase 8's existing enqueue-guard tests as the regression net for the `AdminPageSlugs` whitelist consumer path. No new PHPUnit test file added by Feature 010. If TASK-6 requires an `AdminPageSlugs::plugin_screen_ids()` whitelist extension, a small unit test may be added at that point, but not for Menu.php's registration behavior itself.
- Q: Feature 011 (BerlinDB Query refactor) sequencing relative to `feature/issue-3 → main` cutover → A: Non-blocking post-cutover polish. `feature/issue-3 → main` can merge with the custom Query classes in place. Feature 011 tracked as a follow-up epic without a blocking date. Rationale: custom Query classes work correctly (Phase 2 test coverage + PR #6 / PR #11 consumers), real-BerlinDB adoption is a maintenance improvement not a functional requirement.

---

## User Scenarios & Testing *(mandatory)*

### User Story 1 — Site Administrator Installs the Plugin on WP 6.9 / PHP 8.1 (Priority: P1)

A site administrator downloads the plugin ZIP, uploads it to their WordPress 6.9 install running PHP 8.1, and activates it. The plugin activates cleanly, the admin menu appears at the expected URL, and no PHP deprecation notices appear in `debug.log`.

**Why this priority**: Zero-regression activation is the migration's zero-regression promise. If Feature 010 breaks activation, the entire `feature/issue-3 → main` cutover is blocked.

**Independent Test**: On a WP 6.9 + PHP 8.1 install, `wp plugin activate acrossai-mcp-manager` returns success. `wp-content/debug.log` (with `WP_DEBUG=true`) has zero deprecation notices attributable to the plugin. Admin menu at `/wp-admin/admin.php?page=acrossai_mcp_manager` returns HTTP 200.

**Acceptance Scenarios**:

1. **Given** a WP 6.9 install + PHP 8.1 runtime + composer deps installed, **When** the site admin activates the plugin via wp-admin/plugins.php, **Then** activation succeeds and the plugin's admin menu appears.
2. **Given** a WP 6.9 install + PHP 8.0 runtime, **When** the site admin attempts to activate the plugin, **Then** WordPress refuses activation with the "Requires PHP 8.1" message (plugin header enforces the constraint).
3. **Given** the plugin is activated, **When** `wp-content/debug.log` is inspected (with `WP_DEBUG=true`), **Then** zero deprecation notices reference plugin classes/functions.

---

### User Story 2 — Developer Runs `composer install` from a Clean Checkout (Priority: P1)

A developer clones the repo and runs `composer install`. The resolver reads `composer.json`, downloads all 5 required packages (jetpack-autoloader ^5.0, wpb-access-control ^2.0.0, berlindb/core ^3.0.0, acrossai-co/main-menu ^0.0.8, plus the existing require-dev tools), and produces a `composer.lock` file that pins each package to a specific version.

**Why this priority**: A clean-checkout install is the baseline developer experience. If any package fails to resolve (typo, wrong version constraint, missing repository), the entire feature is blocked.

**Independent Test**: `rm -rf vendor/ composer.lock && composer install` completes with exit 0. `composer.lock` includes entries for all 5 required packages plus jetpack-autoloader.

**Acceptance Scenarios**:

1. **Given** a clean checkout with no `vendor/` or `composer.lock`, **When** `composer install` runs, **Then** exit code is 0 and all 5 required packages are present in `vendor/`.
2. **Given** `composer.json` is edited, **When** `composer validate --strict` runs, **Then** exit code is 0 and the file is reported as valid.
3. **Given** the composer install succeeded, **When** `composer show` lists installed packages, **Then** `wpboilerplate/wpb-access-control`, `berlindb/core`, `acrossai-co/main-menu`, and `automattic/jetpack-autoloader ^5.0` all appear with pinned versions.

---

### User Story 3 — Site Administrator Uses the Admin Menu at Its Historical URL (Priority: P1)

A site administrator navigates to the plugin's admin surface via `/wp-admin/admin.php?page=acrossai_mcp_manager` (either via the WordPress admin menu OR via an external bookmark / Application Password callback URL). The menu renders using the `acrossai-co/main-menu` package's chrome, all submenus resolve, and the URL slug is unchanged from prior phases.

**Why this priority**: External Application Password callback URLs, integration test suites, and user bookmarks all embed the `?page=acrossai_mcp_manager` slug. Silently changing it would break every one of these dependencies. This story validates that Feature 010 delivers the main-menu migration WITHOUT breaking URL contracts.

**Independent Test**: `curl -b <admin-cookies> http://<local-site>/wp-admin/admin.php?page=acrossai_mcp_manager` returns HTTP 200 with the plugin menu chrome visible. Each submenu link resolves to a 200 with its own screen chrome.

**Acceptance Scenarios**:

1. **Given** the plugin is activated + `acrossai-co/main-menu` is installed via composer, **When** the site admin visits the plugin admin URL, **Then** the response is HTTP 200 with the menu chrome and submenu links visible.
2. **Given** the same state, **When** the site admin clicks each submenu (MCP Manager main / CLI Auth Log / Access Control if the wpb-access-control vendor package is present), **Then** each screen resolves to a 200 with its own chrome. Settings-related UI is rendered inline from the MCP Manager main page (not a separate submenu — see FR-020).
3. **Given** the admin URL contract, **When** an external Application Password callback references `?page=acrossai_mcp_manager`, **Then** the callback resolves to the plugin admin surface (URL unchanged post-migration).
4. **Given** Phase 8's admin asset enqueue guard (screen-ID whitelist), **When** the site admin loads a non-plugin admin screen (Dashboard, Posts, Comments, Users, Tools), **Then** zero `acrossai-mcp` handles appear in the HTML response.

---

### User Story 4 — Prior Feature Regressions Pass (Priority: P1)

Every prior migration feature's behavior is preserved after Feature 010 lands:

- **Feature 4/9 (MCP Controller)**: Enabling an MCP server row still boots `\WP\MCP\Plugin::instance()` and registers endpoints.
- **Feature 5 (OAuth)**: `/.well-known/oauth-authorization-server` returns valid JSON; consent surface renders.
- **Feature 6 (REST CLI)**: `POST /wp-json/acrossai-mcp-manager/v1/auth/start` returns a valid `auth_url`.
- **Feature 7 (Frontend CLI)**: `/acrossai-mcp-manager/?action=cli_auth&code=x&server=x` triggers the consent flow.
- **Feature 8 (Assets)**: front-end pages have zero `acrossai-mcp` handles; OAuth consent page has the `acrossai-mcp-frontend-oauth` handle.

**Why this priority**: Feature 010 touches shared infrastructure (composer, admin menu, PHP version). Any regression breaks a downstream feature that ships in the same `feature/issue-3` branch. Regression suite = release gate.

**Independent Test**: Manual walkthrough on WP 6.9 / PHP 8.1 exercises the 5 regression checks above; all pass.

**Acceptance Scenarios**:

1. **Given** an enabled MCP server row + `wordpress/mcp-adapter` installed, **When** any front-end or REST request fires, **Then** `\WP\MCP\Plugin::instance()` gets called and the server is registered as an adapter endpoint (Feature-009 regression).
2. **Given** OAuth authorize endpoint, **When** `curl /.well-known/oauth-authorization-server` runs, **Then** the response is valid JSON with the expected metadata keys (Feature-005 regression).
3. **Given** a CLI auth request, **When** `POST /auth/start` fires with a valid `server_id`, **Then** the response includes `auth_code` + `auth_url` (Feature-006 regression).
4. **Given** the CLI consent URL, **When** a logged-in user visits it, **Then** the consent form renders with the transient-bound server slug (Feature-007 regression — SEC-001 anti-spoof preserved).
5. **Given** any non-plugin front-end page, **When** it's requested, **Then** the HTML contains zero `acrossai-mcp` asset handles (Feature-008 regression).

---

### User Story 5 — Developer Runs Quality Gates and Sees Green (Priority: P2)

A developer working on the plugin runs `vendor/bin/phpcs` and `vendor/bin/phpstan analyse --level=8` on the files touched by Feature 010. Both tools return 0 errors on the touched files. Full-project PHPCS still shows the pre-existing baseline (~505 errors in 59 files, all in code NOT touched by Feature 010) — no new regressions.

**Why this priority**: The quality gates are the automated regression net for the feature. If PHPCS or PHPStan surface issues on touched files, the feature is not merge-ready.

**Independent Test**: `vendor/bin/phpcs composer.json admin/Partials/Menu.php includes/Utilities/AdminPageSlugs.php` returns 0 errors. `vendor/bin/phpstan analyse admin/Partials/Menu.php includes/Utilities/AdminPageSlugs.php --level=8` returns 0 errors.

**Acceptance Scenarios**:

1. **Given** Feature 010's edits are in place, **When** PHPCS runs on touched files, **Then** exit code is 0 with 0 errors + 0 warnings.
2. **Given** the same state, **When** PHPStan runs at level 8 on touched files, **Then** exit code is 0 with 0 errors.
3. **Given** the same state, **When** full-project PHPCS runs, **Then** the error count is at parity with the pre-Feature-010 baseline (no new errors introduced).

---

### Edge Cases

- **Composer resolver conflict** — A newly-added package might require a version of `automattic/jetpack-autoloader` that conflicts with `^5.0`. Composer will surface the conflict at resolve time; escalate before merge.
- **`acrossai-co/main-menu` package auto-hooks `admin_menu`** — If the package registers itself internally, the Loader wiring in `Includes\Main::define_admin_hooks()` must be removed for `Menu.php` to avoid double registration. If the package expects manual hook wiring, keep the Loader entry. Discovery in TASK-1.
- **Screen ID prefix drift** — If `acrossai-co/main-menu` produces screen IDs like `toplevel_page_acrossai-mcp-manager` instead of `toplevel_page_acrossai_mcp_manager`, `AdminPageSlugs::plugin_screen_ids()` needs an update (TASK-6). Existing admin asset enqueue guard (Phase 2 / 3) depends on this whitelist matching the actual screen ID.
- **PHP 8.1 deprecation notices** — Bumping from 8.0 → 8.1 may surface deprecation warnings from third-party code (WordPress core, other plugins). Feature 010 only asserts zero deprecations attributable to THIS plugin — third-party deprecations are out of scope.
- **`vendor/autoload_packages.php` shape drift** — Jetpack autoloader ^5.0 might emit a slightly different manifest shape than ^3.0. If plugin bootstrap breaks after regeneration, investigate before merging (contingency: pin to `^5.0.0` explicit or fall back to `^4.0`).
- **`class_exists()` guards are load-bearing** — Even though `wpb-access-control` becomes a hard require, the 4 `class_exists()` guards MUST stay per CONSTRAINTS. If the plugin ever ships with a broken `vendor/` (e.g. deploy pipeline glitch), the guards prevent activation failure.

---

## Requirements *(mandatory)*

### Functional Requirements

#### `composer.json` (TASK-2)

- **FR-001**: `composer.json` `require.php` MUST be `">=8.1"` (was `">=7.4"`).
- **FR-002**: `composer.json` `require."automattic/jetpack-autoloader"` MUST be `"^5.0"` (was `"^3.0"`).
- **FR-003**: `composer.json` `require` MUST include `"wpboilerplate/wpb-access-control": "^2.0.0"`. Because `wpb-access-control` is NOT on Packagist, `composer.json` MUST ALSO include a `repositories` array entry: `{"type":"vcs","url":"https://github.com/WPBoilerplate/wpb-access-control"}`. Without the VCS entry, `composer install` fails with "Package not found" (confirmed via `acrossai-abilities-manager` reference plugin's `composer.json`).
- **FR-004**: `composer.json` `require` MUST include `"berlindb/core": "^3.0.0"` (available on Packagist; no VCS entry needed).
- **FR-005**: `composer.json` `require` MUST include `"acrossai-co/main-menu": "0.0.10"` (exact pin, matches the version-pin style used by the `acrossai-abilities-manager` reference plugin). Available on Packagist via GitHub API dist.
- **FR-006**: `composer.json` `config.allow-plugins` MUST preserve the 2 existing entries (`dealerdirect/phpcodesniffer-composer-installer`, `automattic/jetpack-autoloader`). NO new `allow-plugins` entries are required for the 3 new packages — none of `wpb-access-control`, `berlindb/core`, or `main-menu` ship composer plugins (confirmed via `acrossai-abilities-manager` reference).
- **FR-007**: `composer.json` `require-dev`, `autoload`, `minimum-stability`, `name`, `type`, `license`, `description`, `homepage`, `keywords`, `support`, `authors` sections MUST be preserved unchanged. If not already present, ADD `"prefer-stable": true` (matches reference plugin — safer under `minimum-stability: dev`).
- **FR-008**: `composer validate --strict` MUST exit 0 after the edits.
- **FR-009**: `composer update` MUST resolve successfully and produce a `composer.lock` recording all 5 required packages with pinned versions.

#### PHP version sync points (TASK-3, atomic per CONSTRAINT 4)

- **FR-010**: `acrossai-mcp-manager.php` plugin header MUST read `Requires PHP: 8.1` (was `8.0`).
- **FR-011**: `README.txt` header MUST read `Requires PHP: 8.1` (was `8.0`).
- **FR-012**: `.specify/memory/constitution.md` tech-stack section MUST reference PHP 8.1 wherever it currently references 8.0.
- **FR-013**: `.github/copilot-instructions.md` MUST reference PHP 8.1 wherever it currently references 8.0.
- **FR-014**: TASK-2 and TASK-3 edits MUST ship in the SAME commit — no interim state where composer.json permits an install that the plugin header would reject.

#### Vendor autoload regeneration (TASK-4)

- **FR-015**: `vendor/autoload_packages.php` MUST be regenerated via `composer dump-autoload -o` (optimized classmap) after the composer.json edits land.
- **FR-016**: `vendor/composer/jetpack_autoload_classmap.php` MUST be present and freshly emitted.
- **FR-017**: Regenerated `vendor/` files MUST be committed alongside the composer.json + composer.lock changes.

#### Admin menu migration (TASK-1 + TASK-5 + TASK-6 + TASK-7)

- **FR-018**: `admin/Partials/Menu.php` MUST register the plugin's admin surface as a **submenu** under the shared `acrossai` parent slug (owned by `acrossai-co/main-menu`, namespace `\AcrossAI_Main_Menu\`). The registration MUST use `add_submenu_page( 'acrossai', ..., 'acrossai_mcp_manager', [ $this, 'contents' ], <position> )` — matching the pattern in `acrossai-abilities-manager/admin/Partials/Menu.php`. The `Menu.php` file MAY import `use \AcrossAI_Main_Menu\SettingsPage;` and reference `SettingsPage::PARENT_SLUG` (value `'acrossai'`) instead of hardcoding the parent slug string.
- **FR-019**: The plugin admin URL slug `acrossai_mcp_manager` MUST be preserved. The URL `/wp-admin/admin.php?page=acrossai_mcp_manager` MUST resolve to the plugin's main admin surface post-migration (per CONSTRAINT 5). Submenu URLs behave identically to top-level URLs — `admin.php?page=<slug>` works for both, so the slug preservation is trivially satisfied.
- **FR-020**: Submenu order and labels MUST be preserved as sibling submenus under the `acrossai` parent, using explicit `$position` arguments to `add_submenu_page` to maintain deterministic ordering. **Assigned positions for Feature 010** (chosen to avoid collision with `acrossai-abilities-manager`'s position 1 for Abilities; verified against actual `admin/Partials/Menu.php` at 2026-07-02):
  - **Position 2** — MCP Manager main (renders the Servers listing via `render_list_page`) — slug `acrossai_mcp_manager` (constant `AdminPageSlugs::PARENT`)
  - **Position 3** — CLI Auth Log — slug `acrossai_mcp_manager_cli_auth_log` (constant `AdminPageSlugs::CLI_AUTH_LOG`)
  - **Position 4** — Access Control — slug `acrossai_mcp_manager_access_control` (constant `AdminPageSlugs::ACCESS_CONTROL`); CONDITIONAL on `class_exists( '\WPBoilerplate\AccessControl\AccessControlManager' )` per CONSTRAINT 1 / FR-025
  
  **Not registered as separate submenus** (documentation clarification, 2026-07-02 post-implementation): "Settings" is not a separate submenu — Settings-related UI is rendered inline from the MCP Manager main page via `Settings::render_list_page()`. "OAuth Audit" is not a submenu in the current codebase (any OAuth audit surface remains a future-work concern, out of scope for Feature 010).
  
  Positions 5+ are reserved for future AcrossAI plugins (Models, Additional flows). Sibling plugins choosing positions in this range risk collision; document any known conflicts at T004 execution.
- **FR-021**: The `acrossai-co/main-menu` package auto-hooks `admin_menu` internally (confirmed via `vendor/acrossai-co/main-menu/src/SettingsPage.php` — `add_action( 'admin_menu', ... )` inside the package's constructor). Therefore the direct Loader wiring for `Menu.php` in `includes/Main.php::define_admin_hooks()` — specifically the `add_action('admin_menu', 'Menu::register_menu')` entry — MUST be REPLACED with a Loader entry that hooks `Menu::register_submenu` on `admin_menu` at default priority. The `Menu::register_menu` method (which called `add_menu_page`) MUST be replaced by `Menu::register_submenu` (which calls `add_submenu_page`).
- **FR-022**: `includes/Utilities/AdminPageSlugs::plugin_screen_ids()` MUST be updated to reflect the top-level → submenu transition. Post-migration, `get_current_screen()->id` on the plugin's main page returns `acrossai_page_acrossai_mcp_manager` (submenu of `acrossai` parent) instead of `toplevel_page_acrossai_mcp_manager`. The whitelist MUST be extended ADDITIVELY per Feature-008's A9 invariant: the old `toplevel_page_*` IDs remain (defensive against multi-plugin ordering where another AcrossAI plugin's older copy of main-menu wins jetpack-autoloader resolution and re-registers our plugin as top-level), the new `acrossai_page_*` IDs are added.
- **FR-023**: All admin URLs referenced in `admin/Partials/*.php` (via `admin.php?page=…` patterns) MUST continue to resolve post-migration.
- **FR-024**: All capability checks (`current_user_can`, `manage_options`) in `admin/Partials/*.php` MUST match the shared `acrossai` parent menu's capability convention — reference plugin uses `manage_options` throughout, which is the default. No capability changes required.

#### Shared parent menu bootstrap (NEW)

- **FR-029**: The plugin's main entry file `acrossai-mcp-manager.php` MUST bootstrap `\AcrossAI_Main_Menu\SettingsPage` on `plugins_loaded` priority 0. Reference implementation (from `acrossai-abilities-manager.php` lines 142–154):
  ```php
  add_action(
      'plugins_loaded',
      static function () {
          if ( did_action( 'acrossai_main_menu_bootstrapped' ) ) {
              return;
          }
          if ( class_exists( \AcrossAI_Main_Menu\SettingsPage::class ) ) {
              new \AcrossAI_Main_Menu\SettingsPage();
              do_action( 'acrossai_main_menu_bootstrapped' );
          }
      },
      0
  );
  ```
  The `did_action()` guard makes the bootstrap idempotent across multiple AcrossAI plugins consuming the same shared menu. The `class_exists()` guard = defense-in-depth per Constitution §V Integration Resilience (graceful degradation when the package is absent — submenus simply won't have a parent rather than fataling).
- **FR-030**: The plugin's main entry file MUST include a **pre-activation vendor autoload guard** registered at `activate_<plugin>` priority 1 (BEFORE the default-priority-10 activation callback). Reference implementation (from `acrossai-abilities-manager.php` lines 82–96):
  ```php
  add_action(
      'activate_' . plugin_basename( __FILE__ ),
      static function () {
          if ( ! file_exists( __DIR__ . '/vendor/autoload_packages.php' ) ) {
              wp_die(
                  esc_html__(
                      'AcrossAI MCP Manager cannot activate: the Composer autoloader is missing. Run "composer install" inside the plugin directory and try again.',
                      'acrossai-mcp-manager'
                  )
              );
          }
      },
      1
  );
  ```
  Without this guard, a missing `vendor/autoload_packages.php` fatals the default-priority-10 activation callback before reaching any graceful-degradation branch.
- **FR-031**: The FR-029 bootstrap is an **accepted deviation from architecture constraint A1** (recorded in `docs/memory/ARCHITECTURE.md` — "all hook registration lives exclusively in `includes/Main.php` via `define_admin_hooks()` / `define_public_hooks()`"). This is NOT a Constitution §I amendment — §I of `constitution.md` states "Modular Architecture" at principle level; A1 is the architectural enforcement rule. Rationale: the shared parent menu must be canonically owned by the plugin entry file (independent of any single Loader lifecycle) so multiple AcrossAI plugins can coexist without race conditions on who owns the `acrossai` parent. This deviation mirrors `acrossai-abilities-manager` Feature 038's `DEC-EXTERNAL-PACKAGE-HOOK-CTOR` scope extension. Per D13 ("escalate to constitution.md when the deviation describes a generalizable pattern ≥2 features"), the pattern IS generalizable (Feature 038 + Feature 010) — so it MUST be registered as: (a) a durable memory entry (**D15** in `docs/memory/DECISIONS.md` + INDEX.md), AND (b) an accepted deviation row (**DEV4** in `docs/memory/INDEX.md`'s Accepted Deviations table). The deviation is scoped to THIS single bootstrap only — all other hook wiring in Feature 010 remains Loader-based per A1. Task T012a implements the registration.

#### Preserved invariants (CONSTRAINTS 1–5)

- **FR-025**: The 4 existing `class_exists( '\WPBoilerplate\AccessControl\AccessControlManager' )` guards in `includes/Main.php`, `includes/REST/CliController.php`, `admin/Partials/Settings.php`, and `admin/Partials/Menu.php` MUST remain in place (per CONSTRAINT 1).
- **FR-026**: Custom `Includes\Database\CliAuthLog\Query`, `Includes\Database\OAuthToken\Query`, `Includes\Database\OAuthAudit\Query`, and `Includes\Database\MCPServer\Query` classes MUST NOT be refactored to consume real `berlindb/core` in this feature (per CONSTRAINT 2). Deferred to Feature 011.
- **FR-027**: PHP 8.1-specific language features (`readonly` properties, `enum`, `never` return type, first-class callable syntax) MUST NOT be introduced in files touched by Feature 010 (per CONSTRAINT 3).

#### BerlinDB future-work documentation (TASK-8)

- **FR-028** *(clarified 2026-07-01)*: `specs/010-composer-dependencies/research.md` MUST document the real-BerlinDB integration point as future work, enumerating the 4 Query.php files that would need refactoring and estimating the migration cost. This documentation feeds a future `specs/011-berlindb-query-migration/` scoping exercise. **Feature 011 is non-blocking for the `feature/issue-3 → main` cutover** — the main-cutover PR can merge with the custom Query classes in place. Feature 011 is tracked as a follow-up epic without a required date.

### WordPress Requirements

| Field | Value |
|---|---|
| PHP version | **8.1+ (bumped this feature)** |
| WordPress version | 6.9+ (unchanged) |
| Multisite | Single-site only (unchanged) |
| Required Composer packages | `automattic/jetpack-autoloader ^5.0`, `wpboilerplate/wpb-access-control ^2.0.0` (via VCS repo), `berlindb/core ^3.0.0`, `acrossai-co/main-menu 0.0.10` (exact pin) |
| Optional Composer packages | `wordpress/mcp-adapter` (still optional, guarded by `class_exists('\WP\MCP\Plugin')` per Feature-009) |

### Module Placement

| File | Namespace | Action |
|---|---|---|
| `composer.json` | (build config) | **Extend** — 5 require edits + allow-plugins additions |
| `composer.lock` | (build artifact) | **Regenerate** via `composer update` |
| `vendor/autoload_packages.php` | (build artifact) | **Regenerate** via `composer dump-autoload -o` |
| `vendor/composer/jetpack_autoload_classmap.php` | (build artifact) | **Regenerate** — same |
| `acrossai-mcp-manager.php` | (plugin entry) | **Extend** — header `Requires PHP:` bump |
| `README.txt` | (WordPress.org header) | **Extend** — header `Requires PHP:` bump |
| `.specify/memory/constitution.md` | (governance) | **Extend** — tech-stack section |
| `.github/copilot-instructions.md` | (agent guidance) | **Extend** — PHP version pin |
| `admin/Partials/Menu.php` | `AcrossAI_MCP_Manager\Admin\Partials` | **Rewrite** — migrate to `add_submenu_page('acrossai', …)` pattern; import `\AcrossAI_Main_Menu\SettingsPage` for `PARENT_SLUG` constant |
| `includes/Utilities/AdminPageSlugs.php` | `AcrossAI_MCP_Manager\Includes\Utilities` | **Extend (required)** — add `acrossai_page_acrossai_mcp_manager` (submenu ID) to whitelist; retain existing `toplevel_page_*` IDs additively per A9 |
| `includes/Main.php` | `AcrossAI_MCP_Manager\Includes` | **Extend** — update `Menu.php` Loader wiring in `define_admin_hooks()` from `register_menu` → `register_submenu` method target |
| `acrossai-mcp-manager.php` | (plugin entry) | **Extend** — add FR-029 `\AcrossAI_Main_Menu\SettingsPage` bootstrap on `plugins_loaded` priority 0 + FR-030 pre-activation vendor autoload guard on `activate_<plugin>` priority 1 |
| `admin/Partials/{Settings,Notices,MCPServerListTable,ApplicationPasswords,CliAuthLogListTable,SettingsRenderer}.php` | (existing) | **Verify only** — check URL / capability references for post-migration compatibility |
| `specs/010-composer-dependencies/research.md` | (spec artifact) | **New** — TASK-1 API investigation + TASK-8 BerlinDB deferral rationale |

**Hook Registration Rule**: All `add_action('admin_menu', …)` wiring MUST route through `Includes\Main::define_admin_hooks()` via Loader (A1). The `Menu.php` Loader entry is PRESERVED and RETARGETED from `register_menu` → `register_submenu` (main-menu package auto-hooks the PARENT menu; consumer plugins still Loader-wire their own submenu registration per A1). Zero direct `add_action`/`add_filter` calls in `admin/Partials/Menu.php` per A1. The FR-029 shared parent bootstrap in the plugin entry file is an accepted A1 deviation per FR-031 — scoped to that single bootstrap only.

### Admin UI Requirements

This phase introduces NO new admin screens or DataForm surfaces. It rewires the existing admin menu registration to use the `acrossai-co/main-menu` package. All existing screens (MCP Manager main + inline Settings UI + CLI Auth Log + conditional Access Control) render unchanged behaviorally — the only change is the menu registration API.

### REST API Contract

This phase introduces NO REST routes.

### Database / Storage

This phase introduces NO database tables and NO options. `berlindb/core` is added to `vendor/` but no code consumes it (deferred to Feature 011 per FR-026 + FR-028).

### Security Checklist

*(Derived from Constitution §III — verify all that apply)*

- [ ] The 4 `class_exists( '\WPBoilerplate\AccessControl\AccessControlManager' )` guards remain in place (FR-025). Defense-in-depth against `vendor/` autoload failures.
- [ ] Menu registration migrated to `acrossai-co/main-menu` does NOT introduce a new capability escalation surface — the same `manage_options` (or package-mapped equivalent) still gates access (FR-024).
- [ ] Admin URL slug `?page=acrossai_mcp_manager` preserved (FR-019 / CONSTRAINT 5) — Application Password callbacks + external integrations don't break.
- [ ] `class_exists('\WP\MCP\Plugin')` guard from Feature-009 unaffected (this feature does not touch `includes/MCP/Controller.php`).
- [ ] Consent-surface exception (§III amendment, Feature-007) untouched — Feature 010 does not touch consent flows.
- [ ] No `add_action`/`add_filter` calls in `admin/Partials/Menu.php` — all wiring via Loader (A1).

### Key Entities

- **`composer.json` require map**: The 5-package dependency declaration. Post-Feature-010: PHP 8.1 + jetpack-autoloader ^5.0 + wpb-access-control ^2.0.0 + berlindb/core ^3.0.0 + main-menu ^0.0.8.
- **Plugin admin URL slug**: `acrossai_mcp_manager` (embedded in `/wp-admin/admin.php?page=acrossai_mcp_manager`). Load-bearing for external callbacks + bookmarks. MUST be preserved (CONSTRAINT 5 + FR-019).
- **Screen ID whitelist**: `AdminPageSlugs::plugin_screen_ids()` — the canonical source for admin asset enqueue guards (Phase 2/3 convention, per A9). Must match `get_current_screen()->id` output post-migration.
- **Admin menu**: The plugin's WordPress admin menu (top-level + submenus). Currently registered via raw `add_menu_page` / `add_submenu_page`; post-Feature-010 registered via `acrossai-co/main-menu` package API.

---

## Success Criteria *(mandatory)*

### Definition of Done Gates

All of the following MUST pass before Feature 010 is considered complete:

- [ ] `composer validate --strict` — exits 0
- [ ] `composer update` succeeds; `composer.lock` records all 5 required packages with pinned versions
- [ ] `vendor/bin/phpcs` on touched files — 0 errors / 0 warnings
- [ ] `vendor/bin/phpstan analyse --level=8` on touched files — 0 errors
- [ ] Full-project PHPCS — no NEW errors beyond pre-Feature-010 baseline
- [ ] `vendor/bin/phpunit` — all suites pass (requires `bin/install-wp-tests.sh <db>` first)
- [ ] `npm run validate-packages` — passes
- [ ] All 4 pre-ship validation scripts (`validate-structure`, `validate-security`, `detect-deprecations`, `detect-rest-endpoints`) — no NEW findings
- [ ] Manual quickstart on live WP 6.9 / PHP 8.1: activation, admin menu, all 5 prior-feature regressions pass
- [ ] `grep -rn "8\\.0" acrossai-mcp-manager.php README.txt .specify/memory/constitution.md .github/copilot-instructions.md` returns zero PHP-version-related matches
- [ ] `grep -rn "add_menu_page\|add_submenu_page" admin/Partials/` returns zero matches (all migrated to main-menu API)
- [ ] `grep -rn "jetpack-autoloader.*3\\." composer.json composer.lock` returns zero matches (fully bumped to ^5.0)

### Measurable Outcomes

- **SC-001**: `composer validate --strict` exits 0 after the composer.json edits.
- **SC-002**: `composer.lock` records exactly 5 required packages (`php` marker + `automattic/jetpack-autoloader ^5.x` + `wpboilerplate/wpb-access-control ^2.x` + `berlindb/core ^3.x` + `acrossai-co/main-menu 0.0.10`) with pinned versions. `composer.json` includes the `repositories` VCS entry for `wpb-access-control`.
- **SC-003**: `grep -c "Requires PHP: 8\\.0" acrossai-mcp-manager.php README.txt` returns `0`. `grep -c "Requires PHP: 8\\.1" acrossai-mcp-manager.php README.txt` returns `2`.
- **SC-004**: Plugin activates on WP 6.9 / PHP 8.1 install without fatal error and without deprecation notices in `debug.log`.
- **SC-005**: `curl -b <admin-cookies> http://<local-site>/wp-admin/admin.php?page=acrossai_mcp_manager` returns HTTP 200 with the plugin menu chrome visible.
- **SC-006**: `curl` against 3 sampled non-plugin admin URLs (Dashboard, Posts, Users) returns zero `acrossai-mcp-manager` asset handles in the HTML response (Phase 8 admin asset enqueue guard preserved).
- **SC-007**: `grep -rn "add_menu_page\|add_submenu_page" admin/Partials/` returns zero matches.
- **SC-008**: `grep -rn "jetpack-autoloader.*3\\." composer.json composer.lock` returns zero matches.
- **SC-009**: PHPCS WPCS strict on touched PHP files (`admin/Partials/Menu.php`, `includes/Utilities/AdminPageSlugs.php`, `includes/Main.php`, `acrossai-mcp-manager.php`) — **no NEW errors introduced beyond the pre-Feature-010 baseline** (parity with the T003-captured baseline; matches SC-011's phrasing). Composer.json is JSON, not PHP, so PHPCS does not scan it. Pre-existing baseline errors on touched files (Menu.php + AdminPageSlugs.php `instance()`/`__construct()` docblocks + plugin header tab-alignment) predate Feature 010 and are OUT OF SCOPE for this feature per the "no cleanup beyond feature scope" convention — reconsidered in a future cleanup epic.
- **SC-010**: PHPStan level 8 on touched files — 0 errors.
- **SC-011**: Manual regression checks on prior features (4/9, 5, 6, 7, 8) all pass per US4 acceptance scenarios.
- **SC-012**: `acrossai-mcp-manager.php` contains the FR-029 `\AcrossAI_Main_Menu\SettingsPage` bootstrap block on `plugins_loaded` priority 0 AND the FR-030 pre-activation vendor autoload guard on `activate_<plugin>` priority 1. Verify via `grep -c "AcrossAI_Main_Menu\\\\SettingsPage" acrossai-mcp-manager.php` returns ≥ 1 AND `grep -c "vendor/autoload_packages.php" acrossai-mcp-manager.php` returns ≥ 1.
- **SC-013**: After migration, `get_current_screen()->id` on the plugin's main admin page returns `acrossai_page_acrossai_mcp_manager` (submenu ID) AND this ID is present in `AdminPageSlugs::plugin_screen_ids()` return value alongside the retained legacy `toplevel_page_*` IDs. The `acrossai_page_*` prefix derives from `sanitize_title(<parent_title>) . '_page_' . <submenu_slug>`; parent title = `'AcrossAI'` (verified 2026-07-02 in `vendor/acrossai-co/main-menu/src/MenuRegistrar.php:38` — `add_menu_page( __( 'AcrossAI', 'acrossai' ), ... )`).

---

## Assumptions

- **Package APIs stable** — `acrossai-co/main-menu 0.0.10` public API surface is documented in `vendor/acrossai-co/main-menu/README.md` and confirmed by inspecting `acrossai-abilities-manager`'s consumption pattern (Feature 038). Exact pin `0.0.10` avoids caret ambiguity for pre-1.0 versions.
- **`wpb-access-control ^2.0.0` API stable** — the 4 existing consumers use `AccessControlManager::instance()` + permission-check methods. Feature 010 doesn't change these call sites (guards preserved); the ^2.0.0 range allows minor/patch updates within 2.x. Package is NOT on Packagist — must be sourced via VCS repositories entry (FR-003).
- **`berlindb/core ^3.0.0`** — added to composer.json but NOT consumed this feature. Semver breaking changes within 3.x won't affect the plugin because no code imports the namespace. Feature 011 will consume it and pin more precisely if needed. Available on Packagist.
- **jetpack-autoloader 3.x → 5.x is safe for the plugin's bootstrap pattern** — the plugin's `acrossai-mcp-manager.php` requires `vendor/autoload.php` and `vendor/autoload_packages.php`. Jetpack autoloader ^5.0 preserves these entry points (verified via `acrossai-abilities-manager` which ships ^5.0 successfully).
- **PHP 8.1 baseline** — the plugin's existing code does not use PHP 7.4 or 8.0-specific features that were removed in 8.1. PHP 8.1 introduces `readonly` / `enum` / `never` / first-class callable but Feature 010 does not introduce these (per FR-027).
- **The `acrossai-co/main-menu` package does NOT internally require `\WPBoilerplate\AccessControl\AccessControlManager`** — confirmed via `vendor/acrossai-co/main-menu/composer.json` in the reference plugin. `wpb-access-control` is required by THIS plugin's own consumers (guards in Menu.php / Settings.php / CliController.php / Main.php), not transitively via main-menu. FR-003 still applies.
- **Main-menu package auto-hooks `admin_menu` internally** — confirmed via `vendor/acrossai-co/main-menu/src/SettingsPage.php:53` (`add_action( 'admin_menu', [...] )` inside the constructor). Consumer plugins register their own submenus via standard `add_submenu_page( 'acrossai', ... )` — not through a custom package API (FR-018 / FR-021).
- **Multi-plugin coordination via jetpack-autoloader version resolution** — if multiple AcrossAI plugins ship `acrossai-co/main-menu`, only the highest-version copy boots. The FR-029 `did_action()` guard makes the consumer bootstrap idempotent. Feature 010's pin `0.0.10` is intentionally one patch higher than `acrossai-abilities-manager`'s `0.0.9` so this plugin's copy wins if both are active — safe for the shared parent menu contract.
- **Existing prior-feature regressions are covered by manual walkthrough** — Feature 010 does not add automated regression tests for Features 4/5/6/7/8. Existing PHPUnit suites in `tests/phpunit/*` continue to run.
- **`bin/install-wp-tests.sh` is available in dev environments** — some quality gates (SC-004 activation smoke, PHPUnit) require a running WP-PHPUnit harness. If unavailable, the deferred gates are documented for the release-prep step.
- **Composer lock updates are committed** — per prior-phase convention (Features 5–8 committed vendor/ and build/ artifacts), Feature 010's `composer.lock` and regenerated `vendor/*` files are committed alongside the source edits.

---

## Dependencies

| Phase | Dependency | Status |
|---|---|---|
| Feature-002 (Admin UI) | `admin/Partials/Menu.php` + `AdminPageSlugs::plugin_screen_ids()` exist and follow the Phase 2/3 pattern | ✅ shipped (PR #5) |
| Feature-005 (OAuth) | OAuth consent surface renders + `\WPBoilerplate\AccessControl\AccessControlManager` optional integration | ✅ shipped (PR #7) |
| Feature-006 (REST CLI) | REST controller uses `AccessControlManager` via `class_exists()` guard | ✅ shipped (PR #8) |
| Feature-007 (Frontend CLI) | Consent surface exception (§III amendment) + FrontendAuth boundary | ✅ shipped (PR #9) |
| Feature-008 (Assets) | Admin asset enqueue guard depends on `AdminPageSlugs::plugin_screen_ids()` — Feature 010 MUST preserve this | ✅ shipped (PR #10) |
| Feature-009 (MCP Controller) | `\WP\MCP\Plugin::instance()` guard pattern — Feature 010 does NOT modify | ✅ shipped (PR #11) |

**Cross-feature invariant**: Feature 010 must NOT modify Feature-008's admin asset enqueue guard behavior. The `AdminPageSlugs::plugin_screen_ids()` return value is load-bearing for that guard. Any TASK-6 update MUST be additive (extend, don't replace).

---

## Non-Goals (Out of Scope This Phase)

- **Refactoring custom `Includes\Database\...\Query` classes to consume real `BerlinDB\Base`** — deferred to Feature 011 per FR-026 + FR-028. Feature 010 adds the package to `vendor/` but does not import it into any active namespace. *(Per 2026-07-01 clarification)* — Feature 011 is a **post-cutover polish** and does NOT block the `feature/issue-3 → main` merge.
- **Removing `class_exists()` guards for `\WPBoilerplate\AccessControl\AccessControlManager`** — per CONSTRAINT 1 / FR-025, guards STAY in place as defense-in-depth. Removal deferred to a future feature after 3+ months of soak time.
- **Introducing PHP 8.1-specific language features** — per CONSTRAINT 3 / FR-027, no `readonly` / `enum` / `never` / first-class callable syntax in files touched this feature. Language-level modernization is a separate concern.
- **Bumping WordPress version** — `Tested up to:` in README.txt stays unchanged.
- **Modernizing the admin menu structure** (adding new submenus, removing old ones, restructuring the URL scheme) — Feature 010 is a pure migration to the main-menu package's API; structural changes are out of scope.
- **Adding tests for the composer changes themselves** — composer.json edits are validated by `composer validate --strict`, not PHPUnit.
- **New PHPUnit test file for `admin/Partials/Menu.php` migration** *(per 2026-07-01 clarification)* — Menu.php's registration behavior is validated by the manual smoke test (SC-005 curl for HTTP 200 + menu chrome). Phase 8's `tests/phpunit/FrontendAuth/../EnqueueAssetsTest.php` + the existing `admin/Main.php::enqueue_styles/scripts` guard serve as the regression net for the `AdminPageSlugs` whitelist consumer path. If TASK-6 requires a whitelist extension, a small `tests/phpunit/Utilities/AdminPageSlugsTest.php` MAY be added at that point — otherwise no new PHPUnit files this feature.
- **CI infrastructure work** — CI gates like `npm ci && composer install && …` are release-prep tasks, not Feature 010's scope.
