# Feature Specification: CLI Auth URL Cache-Exclusion Notice

**Feature Branch**: `feat/cli-auth-cache-exclusion-notice`
**Created**: 2026-08-09
**Status**: Shipped (PR #70)
**Input**: User request: "When the 'Allow CLI connections via npm / npx' setting is enabled, show a notice using the `acrossai_notices` hook that this URL needs to be excluded from cache — like we have done in `acrossai-ai-connectors`."

## Summary

When the operator enables **Settings → MCP → npm / CLI Settings → Allow CLI connections via npm / npx** (`acrossai_mcp_npm_login_enabled = true`), publish a persistent warning to the cross-plugin `acrossai_notices` collection instructing the operator to exclude the frontend CLI authorization URL from page caching. The notice surfaces on the shared Notices submenu and the WP-native dismissible summary provided by `acrossai-co/main-menu` 0.0.30+.

An inline `<div class="notice notice-warning">` banner already exists in `SettingsMenu::render_npm_section_description()` (visible only when the operator is on the settings section). This feature adds a **second, more visible surface** — the shared Notices collection — so co-admins and future visitors who never open that specific section still see the warning.

## User Story - Operator enables CLI flow, gets a persistent cache-exclusion reminder (Priority: P1)

An administrator toggles "Allow CLI connections via npm / npx" ON to expose the npx CLI command to server-edit pages. The CLI flow depends on a frontend authorization page (`https://<site>/acrossai-mcp-manager/`) that carries per-request nonces and single-use auth codes. If a full-page cache or CDN caches this URL, every subsequent CLI login attempt will silently fail.

After saving the setting, the operator (and every other admin on the site) sees a persistent warning card in the AcrossAI Notices submenu titled **"Exclude CLI auth URL from page cache"** naming the exact URL. The card stays until an admin explicitly dismisses it (per-user fingerprint dismissal handled by the vendor rendering layer).

**Why P1**: silent authentication failure is the worst UX for a login flow — the CLI never surfaces the underlying cause. A cache-exclusion warning has to be visible outside the section that owns the toggle, because the operator only visits that section once per lifetime of the setting.

**Independent Test**:
1. Enable **Allow CLI connections via npm / npx** and save.
2. Navigate away from the MCP settings tab to any other admin screen.
3. Open **AcrossAI → Notices** — the "Exclude CLI auth URL from page cache" warning MUST be listed with the site's actual frontend auth URL.
4. Disable the setting and reload — the warning MUST disappear.

**Acceptance Scenarios**:
1. **Given** `acrossai_mcp_npm_login_enabled = false`, **When** an admin opens any admin screen, **Then** the CLI cache-exclusion notice MUST NOT appear in the shared Notices collection.
2. **Given** `acrossai_mcp_npm_login_enabled = true` and `FrontendAuth::get_base_url()` returns `https://example.test/acrossai-mcp-manager/`, **When** an admin opens the AcrossAI Notices submenu, **Then** a warning card with id `acrossai_mcp_manager_cli_auth_cache_exclusion` MUST list that URL inside a `<code>` tag inside its message body.
3. **Given** the notice is showing, **When** an admin dismisses it, **Then** the dismissal MUST persist for that admin only (vendor per-user fingerprint dismissal contract).

## Functional Requirements

- **FR-001**: `Notices::register_shared_notices()` MUST append a notice array to the incoming `$notices` list when — AND ONLY when — `get_option( 'acrossai_mcp_npm_login_enabled', false )` returns truthy.
- **FR-002**: The appended notice MUST use `id = 'acrossai_mcp_manager_cli_auth_cache_exclusion'`, `type = 'warning'`, and `source = 'MCP Manager'`.
- **FR-003**: The `message` field MUST reference the URL returned by `\AcrossAI_MCP_Manager\Public\Partials\FrontendAuth::get_base_url()`, wrapped in `<code>…</code>`, and MUST escape it via `esc_url()`.
- **FR-004**: The notice MUST NOT be added via any other hook (`admin_notices`, `all_admin_notices`, etc.) — it participates only through the `acrossai_notices` filter contract established in `Notices.php:96-111`.
- **FR-005**: The existing inline banner in `SettingsMenu::render_npm_section_description()` MUST remain unchanged — the two surfaces intentionally co-exist (inline for mid-flow context, shared collection for cross-screen visibility).

## Non-Goals

- No new REST endpoint, no new setting, no new DB row.
- No inline dismissal mechanism inside the notice HTML (vendor rendering layer owns dismissal).
- No `WP_CACHE`-based detection — the notice is gated on the plugin's own setting, not on whether a page cache is actually present. Rationale: the URL carries nonces regardless of cache state; the operator's responsibility to exclude it applies in every hosting environment.

## Success Criteria

- **SC-001**: After PR #70 merges, enabling the CLI setting on any environment causes the notice to appear in the vendor Notices submenu within one page load.
- **SC-002**: Disabling the CLI setting causes the notice to disappear within one page load.
- **SC-003**: The URL rendered in the notice matches `home_url( '/' . FrontendAuth::PAGE_SLUG . '/' )` (FrontendAuth's invariant per `tests/phpunit/FrontendAuth/GetBaseUrlTest.php`).
