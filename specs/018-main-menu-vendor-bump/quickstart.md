# Quickstart — Feature 018 Vendor Bump + Settings API Fix

Manual verification recipe for the maintainer. Runs on the developer's local install with `wp-content/debug.log` writable and WP_DEBUG on.

## Prerequisites

- `acrossai-mcp-manager` (Feature 018 build) active.
- `acrossai-abilities-manager` active (reproduces the Jetpack Autoloader class-collision path — the strongest test of the fix). If not active, the fix still works via the fallback branch, but you will not exercise the runtime code path that caused the reported fatal.
- `wp-cli` on `PATH`. Optional but recommended for the `wp option get` verifications below.
- Admin user with `manage_options`.

## Pre-flight snapshot (once)

```bash
# From the plugin root.
grep -n "'acrossai-settings'"      admin/Partials/SettingsMenu.php   # expected: 0 results
grep -n "SettingsPage::tab_page_slug" admin/Partials/SettingsMenu.php  # expected: 0 results
composer show acrossai-co/main-menu | head -3                          # expected: version 0.0.13
```

If any grep returns a match, or `composer show` reports `0.0.11`, the fix has not been applied — do not proceed.

## Save flow (npm toggle)

1. Open `wp-admin` → **Settings → AcrossAI → MCP** tab.
2. Confirm the page renders (no fatal, no white-screen).
3. Confirm the "Enable CLI Connections" checkbox is present.
4. Toggle it ON. Click **Save Changes**.
5. Expected: page reloads with the WordPress "Settings saved." success notice. No red error banner.
6. Verify: `wp option get acrossai_mcp_npm_login_enabled` returns `1`.
7. Toggle the checkbox OFF. Click **Save Changes**.
8. Expected: success notice again.
9. Verify: `wp option get acrossai_mcp_npm_login_enabled` returns `""` (empty — WordPress stores unchecked checkboxes as empty strings) or `0`.

## Save flow (uninstall toggle)

1. Still on Settings → AcrossAI → MCP.
2. Toggle "Delete all data on uninstall" ON. Click **Save Changes**.
3. Expected: success notice.
4. Verify: `wp option get acrossai_mcp_uninstall_delete_data` returns `1`.
5. Toggle OFF. Click **Save Changes**.
6. Expected: success notice.
7. Verify: `wp option get acrossai_mcp_uninstall_delete_data` returns `0`.

## Sibling-tab regression check

1. On the same Settings → AcrossAI page, click any non-MCP tab (e.g. Abilities, if contributed by `acrossai-abilities-manager`).
2. Toggle any control on that tab. Click **Save Changes**.
3. Expected: success notice, no whitelist error, no PHP fatal.
4. Return to the MCP tab. Confirm the toggles you set above are still persisted (Feature 018 did not clobber the sibling tab's Save, and vice versa).

## Log soak (5 minutes)

1. `tail -f wp-content/debug.log` in a spare terminal.
2. Re-run the two Save flows and the sibling-tab regression check.
3. Expected: no `Call to undefined method` line mentioning `SettingsPage::tab_page_slug`. No "not in the allowed options list" line. No new WSOD, no new fatal, no new PHP notice attributable to `admin/Partials/SettingsMenu.php`.

## Gate suite (automated)

```bash
# From the plugin root.
php -l admin/Partials/SettingsMenu.php   # expected: No syntax errors detected
composer phpcs                            # expected: no errors
composer phpstan                          # expected: no errors at level 8, no new baseline
```

## Rollback (if the fix breaks something else)

The vendor bump is what unlocks the `SettingsPage::get_settings_renderer()` call in the patched `register_settings()`. Rolling only `composer.json` back to `0.0.11` will re-fatal the file. If you must roll back, revert BOTH the composer pin AND the `SettingsMenu.php` patch in the same commit.

If `acrossai-abilities-manager` is active on the same install, its own `0.0.13` pin will still win the class-collision race under Jetpack Autoloader — meaning the rollback of THIS plugin's pin does not restore `0.0.11` at runtime. In that case, the only rollback path is to deactivate `acrossai-abilities-manager` too. Do not deploy that combination; forward-fix instead.
