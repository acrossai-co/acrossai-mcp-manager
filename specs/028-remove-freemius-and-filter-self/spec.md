# Feature Specification: Retire Freemius integration; consume main-menu 0.0.22+ filter-driven Add-ons page

**Feature Branch**: `028-remove-freemius-and-filter-self` (implementation lives on `feature/remove-freemius` — see plan.md §Note on branch naming)
**Created**: 2026-07-17
**Status**: Implemented (reverse-engineered from PR #34)
**Input**: User description: "remove the composer require freemius/wordpress-sdk from the main-menu composer package so update the package to 0.0.23 and remove all the code that is related to freemius from this plugins itself. Here in the main-menu we have the filter to add/remove the array and if the current plugin is active please remove it from the filter 'acrossai_addons'."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Plugin ships without a Freemius license/opt-in surface (Priority: P1) 🎯 MVP

Today (F022, main-menu 0.0.18) this plugin bundles the Freemius WordPress SDK as a transitive dependency of `acrossai-co/main-menu`. On activation, the SDK boots an opt-in card that pipes anonymous usage data to `api.freemius.com`, gates the shared Add-ons submenu on `is_registered()`, and adds ~1.5 MB of vendor code. The umbrella product (id 34418 / slug `acrossai-add-ons`) has no paid plans and no live add-on catalog — the entire integration is dead weight for the shipping surface.

**Why this priority**: This is the whole feature. Every other story supports it. Without this, the plugin keeps loading a license-management SDK for a product that doesn't sell anything.

**Independent Test**: Fresh install of the plugin. `ls vendor/freemius/` returns "No such file or directory". No opt-in card renders on plugin activation. No `fs_*` `wp_options` rows are created by *this* plugin (any pre-existing rows from earlier F022 versions are orphan; documented in Out-of-Scope). No `api.freemius.com` request in the browser DevTools Network tab or in `wp-content/debug.log`.

**Acceptance Scenarios**:

1. **Given** a fresh WordPress install with only this plugin active, **When** the operator activates the plugin, **Then** no Freemius opt-in card appears and `SELECT COUNT(*) FROM wp_options WHERE option_name LIKE 'fs_%'` returns `0` for rows this plugin would have created.
2. **Given** the plugin is active, **When** `composer show` runs from the plugin root, **Then** `freemius/wordpress-sdk` is not listed.
3. **Given** the plugin is active, **When** the operator visits any wp-admin page, **Then** no request goes to `api.freemius.com` (verify via DevTools Network filter).
4. **Given** the plugin's PHP source, **When** `grep -rn 'AcrossAI_Addon\|freemius\|fs_dynamic_init\|acrossai-add-ons' includes/ admin/ public/ src/ tests/` runs, **Then** zero matches are returned.

---

### User Story 2 - Active plugin does not advertise itself as an installable add-on (Priority: P2)

`acrossai-co/main-menu` 0.0.22 replaces the Freemius Add-ons page with a card grid driven by `apply_filters( 'acrossai_addons', self::ADDONS )`. The baseline `ADDONS` array in the vendor includes four AcrossAI slugs — one of them is `acrossai-mcp-manager`. When this plugin is active, a card labeled "AcrossAI MCP Manager" would render on the Add-ons page with an "Install" button that is UX-nonsensical (the plugin is already installed and running).

**Why this priority**: UX consistency. An already-active plugin should never appear on an "installable add-ons" page.

**Independent Test**: With this plugin plus another AcrossAI plugin (e.g., `acrossai-abilities-manager`) both active, open the AcrossAI → Add-ons page. The grid renders three cards (Abilities Manager, Model Manager, Turn Off AI Features). The "AcrossAI MCP Manager" card is absent.

**Acceptance Scenarios**:

1. **Given** this plugin is active AND `acrossai-co/main-menu` ≥ 0.0.22 renders the Add-ons page, **When** the operator opens the Add-ons page, **Then** the card for `slug=acrossai-mcp-manager` does not appear in the grid.
2. **Given** this plugin is DEACTIVATED (its `Main::load_hooks()` no longer runs), **When** the operator opens the Add-ons page, **Then** the `acrossai-mcp-manager` card reappears in the grid (baseline vendor behavior restored).
3. **Given** a companion plugin extends `acrossai_addons` with its own entries, **When** the Add-ons page renders, **Then** its entries are preserved alongside the three remaining baseline entries.

---

### User Story 3 - F022 memory (decisions + bugs) is marked Superseded (Priority: P3)

Three durable-memory entries codify the Freemius integration: `DEC-ADDONS-PAGE-VENDOR-CTOR-BOOT` (external-class self-registration exception to A1), `DEC-FREEMIUS-DOUBLE-OPTIN-GATES-ACCOUNT` (opt-in state machine diagnosis), and `B28` (two-level `fs_dynamic_init` menu enablement). Post-F028 they no longer describe any live surface in this plugin, but they retain reference value for other AcrossAI plugins that might still consume Freemius.

**Why this priority**: Memory hygiene per `PATTERN-MEMORY-SUPERSESSION-VS-ANNOTATION` — retired decisions stay for context until an audit prunes them, but must be flagged so future readers don't mistake them for active guidance.

**Independent Test**: `grep 'DEC-ADDONS-PAGE-VENDOR-CTOR-BOOT' docs/memory/INDEX.md` returns a row with `Superseded (F028)` in the Status column. Same for `DEC-FREEMIUS-DOUBLE-OPTIN-GATES-ACCOUNT` and `B28`. The source entries in `DECISIONS.md` and `BUGS.md` show `**Status**: Superseded (Feature 028 — 2026-07-17). …` with a pointer to `docs/planings-tasks/028-remove-freemius-and-filter-self.md`. Bodies below the status line are preserved verbatim.

**Acceptance Scenarios**:

1. **Given** `docs/memory/INDEX.md`, **When** the Active Decisions table is inspected, **Then** rows for `DEC-ADDONS-PAGE-VENDOR-CTOR-BOOT` and `DEC-FREEMIUS-DOUBLE-OPTIN-GATES-ACCOUNT` show Status `Superseded (F028)`.
2. **Given** `docs/memory/INDEX.md`, **When** the Bugs table is inspected, **Then** the `B28` row shows Status `Superseded (F028)`.
3. **Given** `docs/memory/DECISIONS.md` and `docs/memory/BUGS.md`, **When** the two DEC entries and the B28 entry are opened, **Then** each has a `**Status**: Superseded (Feature 028 — 2026-07-17). …` line pointing to the F028 planning doc, and the original body content is retained below.
4. **Given** `docs/memory/WORKLOG.md`, **When** the file is read, **Then** an entry dated `2026-07-17` for `F028` exists and codifies both the vendor-shed retirement pattern and the consumer self-exclusion filter pattern.

---

### Edge Cases

- **What if the operator installed a prior version and has `fs_*` `wp_options` rows?** They are orphaned — nothing this plugin loads reads or writes them. Harmless. Operator can clean them up manually via `DELETE FROM wp_options WHERE option_name LIKE 'fs_%'`. Per D21 (fresh-install-only retirement pattern from F016), the plugin does NOT ship a migration.
- **What if another active plugin also hooks `acrossai_addons` and re-adds this plugin?** `AddonsFilter::remove_self` runs at default priority 10; a caller registering at priority 11+ that re-adds the slug would win. Acceptable — that would be a deliberate override by a companion plugin.
- **What if `apply_filters( 'acrossai_addons', ... )` is called with a non-array value?** `AddonsFilter::remove_self` normalizes non-array input to `array()`. Defensive, matches `SettingsMenu::register_tab`'s non-array-input handling.
- **What if the filter array contains non-array entries** (a rogue caller passes `[..., 'string-instead-of-shape', ...]`)? `AddonsFilter::remove_self` drops non-array entries silently.
- **What if `acrossai-co/main-menu` bumps back to a Freemius-dependent version?** The `class_exists( \AcrossAI_Addon\AddonsPage::class )` guard is gone, so nothing would boot the AddonsPage even if it reappeared. Would require a new feature to re-integrate; not a regression path this feature must guard against.

---

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: `composer.json` MUST pin `acrossai-co/main-menu` to `0.0.23` (or the latest tag that removed the `freemius/wordpress-sdk` dependency; must be `>= 0.0.22`).
- **FR-002**: `composer update acrossai-co/main-menu` MUST uninstall `freemius/wordpress-sdk` transitively (verified by `composer show freemius/wordpress-sdk` failing with "package not found").
- **FR-003**: `includes/Main.php::define_admin_hooks()` MUST NOT contain any reference to `\AcrossAI_Addon\AddonsPage`, `fs_dynamic_init`, `fs_product_id`, `fs_public_key`, `fs_slug`, `fs_menu`, `fs_has_addons`, or `acrossai-add-ons`.
- **FR-004**: `admin/Partials/AddonsFilter.php` MUST exist as a singleton in the `AcrossAI_MCP_Manager\Admin\Partials` namespace with `public function remove_self( mixed $addons ): array`.
- **FR-005**: `remove_self()` MUST drop entries where `$addon['slug'] ?? '' === 'acrossai-mcp-manager'` and MUST `array_values()` the result to re-index numerically from 0.
- **FR-006**: `remove_self()` MUST return `array()` (empty array) when the input is not an array (null, string, false, int, object).
- **FR-007**: `remove_self()` MUST drop non-array entries (strings, ints, null) from the input array before returning.
- **FR-008**: `Main::define_admin_hooks()` MUST register the filter via the Loader: `$this->loader->add_filter( 'acrossai_addons', AddonsFilter::instance(), 'remove_self' )`. Direct `add_filter()` calls MUST NOT be used (per Architecture Constraint A1).
- **FR-009**: `admin/Partials/SettingsMenu.php` and the `acrossai_settings_tabs` filter wiring MUST NOT change — main-menu's Settings page surface is preserved end-to-end.
- **FR-010**: `docs/memory/INDEX.md` MUST flip Status column for `DEC-ADDONS-PAGE-VENDOR-CTOR-BOOT`, `DEC-FREEMIUS-DOUBLE-OPTIN-GATES-ACCOUNT`, and `B28` from `Active (F022)` to `Superseded (F028)`.
- **FR-011**: `docs/memory/DECISIONS.md` and `docs/memory/BUGS.md` MUST update the `**Status**:` line at the source entries with a `Superseded (Feature 028 — 2026-07-17)` marker + one-sentence supersession reason + pointer to `docs/planings-tasks/028-remove-freemius-and-filter-self.md`. Original entry bodies MUST be preserved verbatim (per PATTERN-MEMORY-SUPERSESSION-VS-ANNOTATION).
- **FR-012**: `docs/memory/WORKLOG.md` MUST get a new `2026-07-17 - F028` entry codifying both the vendor-shed retirement pattern and the consumer self-exclusion filter pattern.
- **FR-013**: `docs/memory/INDEX.md` MUST add a new row in the Worklog Entries table for `2026-07-17 | F028 | …`.

### Success Criteria

- **SC-001**: `composer run phpcs` on `admin/Partials/AddonsFilter.php` + `includes/Main.php` returns clean (no violations).
- **SC-002**: `composer run phpstan` (level 8) returns clean.
- **SC-003**: `composer run test --testsuite admin` passes with the 4 new `AddonsFilterTest` cases plus all pre-existing `SettingsMenuTest` cases.
- **SC-004**: On a wp-admin install where this plugin plus at least one other AcrossAI plugin (or any `acrossai_addons` filter consumer) are active, the AcrossAI → Add-ons page renders without the `acrossai-mcp-manager` card.
- **SC-005**: `ls vendor/freemius/` returns "No such file or directory" after `composer install`.
- **SC-006**: The `grep -rn` search in FR-003 returns zero matches inside `includes/`, `admin/`, `public/`, `src/`, `tests/`, `acrossai-mcp-manager.php`, `uninstall.php`, `composer.json`.

---

## Assumptions

- Operators of prior F022 installs are responsible for cleaning orphan `fs_*` `wp_options` rows if they want a fully clean state. Per D21 (fresh-install-only retirement, established by F016), this feature does NOT ship a migration.
- `acrossai-co/main-menu` 0.0.23 is a stable tag that preserves the `AddonsPageRenderer::ADDONS` baseline (containing the `acrossai-mcp-manager` slug). If a future bump renames the filter or removes this plugin's slug from the baseline, the `remove_self` filter becomes a no-op (harmless).
- The plugin's admin menu still needs a Settings page — the `SettingsMenu` tab wiring on the shared main-menu Settings surface stays in place and is out of scope for F028.
- Historical planning/spec docs in `specs/022-addons-page-registration/` and `docs/planings-tasks/022-addons-page-registration.md` are frozen historical record and are NOT edited by F028.
