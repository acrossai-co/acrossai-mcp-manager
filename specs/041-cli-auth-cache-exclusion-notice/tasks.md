---
description: "Task list for Feature 041 — CLI auth URL cache-exclusion notice via acrossai_notices"
---

# Tasks: CLI Auth URL Cache-Exclusion Notice

**Input**: [spec.md](./spec.md), [plan.md](./plan.md)

**Tests**: No new PHPUnit files required for merge. The existing `notices` suite (contract for `register_shared_notices()` dedup + filter contract) continues to pass; the conditional append is small and its behavior is directly verifiable via the post-merge recipe in `plan.md`.

**Organization**: One user story (US1). Feature was shipped in a single small commit; every task below records what actually happened, not a plan to execute.

## Format: `[ID] [Story] Description`

## Path Conventions

- **Plugin root**: `/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/plugins/acrossai-mcp-manager`

---

## Phase 1: Research (existing patterns)

- [X] T001 [US1] Grep the sibling plugin `acrossai-ai-connectors` for the `acrossai_notices` filter usage to confirm the notice-array shape (`id`, `title`, `message`, `type`, `source`). Reference: `acrossai-ai-connectors/admin/Partials/Notices.php` id `acrossai_ai_connectors_page_cache_exclusions_required`.
- [X] T002 [US1] Confirm this plugin's `Notices` class is already wired to `acrossai_notices` — `includes/Main.php:426` (`$this->loader->add_filter( 'acrossai_notices', $notices, 'register_shared_notices' )`). No new wiring needed.
- [X] T003 [US1] Locate the CLI-flow setting: option key `acrossai_mcp_npm_login_enabled` registered in `admin/Partials/SettingsMenu.php:137-144` with default `false` and `rest_sanitize_boolean` sanitizer.
- [X] T004 [US1] Locate the URL resolver: `\AcrossAI_MCP_Manager\Public\Partials\FrontendAuth::get_base_url()` returns `home_url( '/' . FrontendAuth::PAGE_SLUG . '/' )` (invariant pinned by `tests/phpunit/FrontendAuth/GetBaseUrlTest.php`).

## Phase 2: Implementation

- [X] T005 [US1] Append a conditional notice block to `admin/Partials/Notices.php::register_shared_notices()` between the AccessControl and `return $notices` lines. Gate on `(bool) get_option( 'acrossai_mcp_npm_login_enabled', false )`. Notice fields: `id = acrossai_mcp_manager_cli_auth_cache_exclusion`, `title = Exclude CLI auth URL from page cache`, `message` = translator-annotated `sprintf()` embedding the URL from `FrontendAuth::get_base_url()` wrapped in `<code>…</code>` and passed through `esc_url()`, `type = warning`, `source = MCP Manager`. Reference: `admin/Partials/Notices.php:145-158`.
- [X] T006 [US1] Verify PHP lint passes: `php -l admin/Partials/Notices.php` → `No syntax errors detected`.

## Phase 3: Post-Merge Verification (manual, on local site)

- [ ] T007 [US1] Enable **Settings → MCP → Allow CLI connections via npm / npx**, save, navigate to **AcrossAI → Notices**, confirm the "Exclude CLI auth URL from page cache" card is listed with the site's `https://<host>/acrossai-mcp-manager/` URL wrapped in `<code>…</code>`.
- [ ] T008 [US1] Disable the setting → confirm the card disappears on next admin page load.
- [ ] T009 [US1] Re-enable → dismiss the card → confirm dismissal persists across page reloads for that admin user (vendor per-user fingerprint contract).

## Phase 4: Memory & PR

- [X] T010 [US1] Capture the durable pattern to `docs/memory/WORKLOG.md` (2026-08-09 entry: "Gate-tied cache-exclusion warnings belong in `acrossai_notices`, not only inline in the settings section") + matching row in `docs/memory/INDEX.md` Worklog Entries table.
- [X] T011 [US1] Open PR #70 against `main` with the code commit + memory commit. URL: https://github.com/acrossai-co/acrossai-mcp-manager/pull/70.

---

## Ledger

- **Files changed**: `admin/Partials/Notices.php` (+15 lines).
- **Files added**: `specs/041-cli-auth-cache-exclusion-notice/{spec,plan,tasks}.md`, `docs/planings-tasks/041-cli-auth-cache-exclusion-notice.md`.
- **Memory updated**: `docs/memory/WORKLOG.md` + `docs/memory/INDEX.md`.
- **Total LOC (code)**: +15.
