# Quickstart — Phase 2: Admin UI Manual Verification

**Date**: 2026-06-17 | **Branch**: `002-admin-ui` | **Time budget**: ~15 minutes

This is the human-runnable verification script. CI may automate any subset
of these as PHPUnit / Playwright tests, but the canonical "did Phase 2
ship?" answer comes from walking through this list against a fresh install.

---

## Setup

```bash
# 0.1 — Fresh WP 6.9 install, PHP 8.0, WP_DEBUG=true, WP_DEBUG_LOG=true.
# 0.2 — Activate the plugin.
# 0.3 — Confirm the prerequisite BerlinDB Query classes exist:
#       ls includes/Database/MCPServer/Query.php
#       ls includes/Database/CliAuthLog/Query.php
#       If either is absent, STOP — Phase 2 cannot proceed.
```

---

## Walk

### 1. Menu structure (US1)

- Open `/wp-admin/`. Top-level menu **MCP Manager** is present.
- Hover/click. Submenus appear: **Servers**, **CLI Auth Log**, and (if
  `wpb-access-control` is installed) **Access Control**.
- Open Plugins screen. The plugin's row shows a **Settings** action link.
  Click it. Browser arrives at `?page=acrossai_mcp_manager`.

✅ Pass = all three submenus present (or 2 if access-control vendor absent);
plugin row has Settings link.

### 2. Server list (US2)

- On the Servers page, three seeded rows render in a `WP_List_Table` with
  columns: Name, Slug, Status, Registered From, Route Namespace, Route,
  Version, Actions.
- Hover one row. Row actions appear: **Edit**, **Toggle Status**, **Delete**.
- Click **Toggle Status**. Page reloads with a success admin notice; Status
  column flips.
- Select two rows via checkboxes; pick **Delete** from the bulk-action
  dropdown; click Apply. Page reloads; rows are gone; success notice
  reports the deletion count.

✅ Pass = list renders, toggle works, bulk delete works, both produce
notices.

### 3. Create new server (US2.7 / US2.8)

- Click **Add New** on the list page.
- Fill the form: Name `Test`, Slug `test`, Description `test`, Route
  Namespace `acrossai-test/v1`, Route `/items`, Version `1.0.0`. Submit.
- Browser redirects to the edit page for the new row; success notice
  reports creation.
- Click Add New again. Submit with the same slug `test`.
- Page returns to the list with an **error** notice "Slug already in use";
  no row inserted.

✅ Pass = create works on unique slug; collision rejected with notice.

### 4. Edit page tabs (US3)

- Open `?page=acrossai_mcp_manager&action=edit&server=1`.
- Four tabs render in order: **General**, **Tokens**, **Access Control**,
  **Claude Connector**. General is active by default.
- General: change Name to "Renamed". Save. Notice = success. Reload.
  Value persisted.
- Tokens: create a new Application Password. Confirm one-time display and
  that subsequent reload shows the hash row (not the plaintext).
- Access Control (only when vendor pkg present): tab renders vendor
  content; save works.
- Claude Connector: enter Client ID `client123`, Secret `secret456`,
  Redirect URI `https://example.com/cb`. Save. Reload. Client ID is the
  saved value; Secret displays as masked (e.g., `••••••••`); URI is the
  saved value.

✅ Pass = all four tabs save and persist; Tokens flow preserves hashed
storage; Claude Secret masks on re-render.

### 5. Security gates (SC-002 / SC-003)

- Open browser dev tools. On the list page, copy the URL for the **Toggle
  Status** row action. Note the `_wpnonce` query var.
- Paste the URL with `_wpnonce` stripped. Submit. WordPress `wp_die()`
  screen appears. No DB write.
- Log out. Log in as a non-admin (Editor role). Open the list page URL
  directly. The menu is NOT visible. Direct URL navigation shows a
  capability-error page.

✅ Pass = forged-nonce blocked; non-admin blocked.

### 6. Asset guard (SC-004 / US5)

- Open the wp-admin Dashboard. View page source. Search for `backend.js`
  and `backend.css`. **Neither appears.**
- Open the Servers page. View source. **Both appear**, with version + deps
  matching `build/js/backend.asset.php` and `build/css/backend.asset.php`
  (no literal hardcoded version).

✅ Pass = bundles absent from Dashboard, present on plugin pages.

### 7. Adapter notice (US4 / SC-005)

- Confirm `\WP\MCP\Plugin` does NOT exist (the `wordpress/mcp-adapter`
  package isn't installed).
- Reload any wp-admin page. Dismissible warning notice renders:
  "The WordPress MCP adapter package is not installed…"
- Click the X. Reload. **Notice gone for this user.**
- Open the same page in an incognito window as a different admin user.
  Notice **still appears** for them.
- Install the adapter. Reload. Notice gone for everyone (render guard
  short-circuits regardless of dismissal flag).

✅ Pass = dismissal is per-user and sticky; install also clears for all.

### 8. Access-control degradation (SC-006)

- Deactivate `wpb-access-control`.
- Reload wp-admin. **Access Control submenu is gone.**
- Open the edit page → Access Control tab. Tab renders an informational
  notice "Access Control requires the wpb-access-control package." No
  fatal.
- Reactivate. Submenu and tab content return.

✅ Pass = graceful degradation; no fatal in either direction.

---

## Static checks

After the walk:

```bash
# 9.1 — No constructor add_action / add_filter under admin/.
grep -rn 'add_action\|add_filter' admin/
# Expected: empty.

# 9.2 — No MCPServerTable:: / CliAuthLogTable:: under admin/.
grep -rn 'MCPServerTable::\|CliAuthLogTable::' admin/
# Expected: empty.

# 9.3 — No hardcoded asset version in admin/Main.php.
grep -n "wp_enqueue_script\|wp_enqueue_style" admin/Main.php
# Expected: every enqueue uses $asset['version'] and $asset['dependencies']
# — no literal '1.0.0' or array('jquery', ...) inline.

# 9.4 — Phase 1 baseline + Phase 2 surface pass PHPCS.
vendor/bin/phpcs admin/ includes/Main.php
# Expected: 0 errors, 0 warnings.

# 9.5 — PHPStan level 8.
vendor/bin/phpstan analyse admin/ includes/Main.php --level=8
# Expected: 0 errors.

# 9.6 — Validate-packages.
npm run validate-packages
# Expected: pass.
```

---

## Definition of Done summary

If steps 1–8 of the walk pass AND all static checks in step 9 pass,
Phase 2 ships. Mark the DoD gates in `spec.md` complete and proceed to
`/speckit-tasks` follow-up tracking if any.
