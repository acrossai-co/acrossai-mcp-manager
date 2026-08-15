# Quickstart: MCP Quick Setup Wizard (dev runbook)

Dev-facing runbook for setting up, running, and verifying the wizard feature end-to-end on a local WordPress install.

## Prerequisites

- WordPress 6.9+ with `manage_options` admin account
- PHP 8.1+
- Node 18+
- Plugin already `composer install`ed + `npm install`ed
- Optional: `acrossai-abilities-manager` and/or `acrossai-pro` companion plugins present in `wp-content/plugins/` for the tri-state / dual-state tests

## Build

```bash
npm run build              # compiles src/js/quick-setup.js → build/js/quick-setup.{js,css,asset.php}
npm run lint:js            # ESLint pass (Constitution VII gate)
composer run phpcs         # PHPCS pass (Constitution VII gate)
composer run phpstan       # PHPStan level 8 pass (Constitution VII gate)
```

## Manual acceptance path (US1 — first-run admin)

1. **Fresh install** — deactivate + delete the plugin, then reactivate from `wp-admin/plugins.php`.
2. **Verify redirect** — the browser should land on `/wp-admin/admin.php?page=acrossai_mcp_manager&quick-setup=1&step=1` automatically (no intermediate settings page).
   - If it stays on `plugins.php`: check `wp option get _transient_acrossai_mcp_manager_quick_setup_do_redirect` inside 30s of activation — should return `'1'`. If missing, the activation hook didn't fire (broken vendor autoload — see R4 guard).
3. **Step 1** — verify the seeded "Default MCP Server" is listed with route `mcp/mcp-adapter-default-server`. Click the row → Continue enables.
4. **Step 2** — verify the info banner shows the F042 admin-only copy verbatim. AC editor mounts. Click Continue without changing the rule (spec-permitted no-op).
5. **Step 3** — verify:
   - If Abilities Manager NOT active: giant "3" + promo card with WordPress.org install link.
   - If active: giant `N` (real count) + "Enable all" + "Configure one-by-one" (opens `?tab=abilities&server=<id>` in new tab).
6. **Step 4** — if the server is already enabled, this step MUST auto-skip (progress bar shows 4 total steps). If disabled, verify the yellow notice + toggle; flip toggle → Continue enables.
7. **Step 5** — verify 2×2 grid of 4 method cards. Connectors card behavior:
   - AcrossAI Pro missing → "Get AcrossAI Pro →" link to `acrossai.co/pricing/#pricing` + trial trust line below the grid.
   - Pro inactive → yellow notice + "Activate AcrossAI Pro" button.
   - Pro active → radio-selectable cards + 4 provider tabs (ChatGPT / Claude / Gemini / Grok) show canonical MCP URL with Copy button.
8. **Pick MCP Client** → verify 8-pill client row (Claude Desktop, Claude Code, VS Code, GitHub Copilot, Cursor, Codex, Gemini CLI, Custom Client). Selecting a pill reveals JSON config with Copy button.
9. **Finish** → verify completion screen shows 4 summary rows + 3 CTAs.
10. **Post-completion** — verify `?page=acrossai_mcp_manager` (no `quick-setup=1`) renders the list-table normally; the wizard bundle does NOT load (DevTools Network tab).

## Manual acceptance path (US2 — admin bar chip)

1. Load any admin screen (Dashboard, Posts, whatever) as a `manage_options` user.
2. Top admin bar shows "Quick Setup for MCP" chip with wrench dashicon.
3. Click → lands on `?step=1`.
4. Log in as an Editor. Chip MUST be absent.

## Manual acceptance path (US3 — reload restores position)

1. Advance to step 3 with some prior selections.
2. Reload the browser (Cmd/Ctrl+R).
3. Wizard restores step 3 + prior selections.
4. Close the tab, reopen the URL within 30 minutes → prior state still restored.
5. Wait > 30 minutes (or `wp transient delete acrossai_mcp_manager_quick_setup_state_{user_id}`) → wizard restarts fresh at step 1.

## Manual acceptance path (US4 — auto-skip step 4)

1. From wp-admin, enable a server via the existing list-table row action.
2. Open the wizard from the admin bar chip.
3. Pick that already-enabled server on step 1.
4. Advance from step 3 → wizard lands on step 5 (skipping step 4).
5. Browser Back from step 5 → lands on step 3 (skipping 4 in reverse).
6. Progress bar shows 4 total steps, not 5.

## Manual acceptance path (US5 — deep link)

1. Open `?page=acrossai_mcp_manager&quick-setup=1&step=5&method=client` in a new tab (with no active scratchpad).
2. Wizard silently redirects to `?step=1` (unmet precondition — no server picked yet).
3. Complete step 1 + 2 + 3 + (skip 4 if enabled), then reload with the original deep-link URL.
4. Wizard lands on step 5 with MCP Client panel expanded.

## Manual acceptance path (a11y — WCAG 2.1 AA / SC-010)

1. Complete the wizard **using only the keyboard** — no mouse. Tab through fields; Enter to activate buttons; arrow keys where standard. Every interaction MUST be reachable and operable.
2. Run **axe DevTools** scan on every step (steps 1-5 + completion). Zero violations at WCAG 2.1 AA level.
3. With a screen reader (VoiceOver / NVDA), advance a step and verify the announcement: "Step 2 of 5, Access Control" (or similar phrasing).
4. Verify Continue button's disabled state announces reason (e.g., "Continue button, disabled" or better).
5. Verify progress bar exposes `role="progressbar"` with `aria-valuenow` matching current step.

## Debug commands

```bash
# Inspect scratchpad content for the current user
wp transient get acrossai_mcp_manager_quick_setup_state_1   # user_id=1

# Force-clear scratchpad
wp transient delete acrossai_mcp_manager_quick_setup_state_1

# Simulate a fresh activation redirect
wp transient set acrossai_mcp_manager_quick_setup_do_redirect 1 30

# Verify wizard bundle is NOT enqueued on non-wizard pages
# (visit ?page=acrossai_mcp_manager, DevTools Network filter for quick-setup — expect zero hits)
```

## Rollback

If the wizard causes any regression, disable via `wp plugin deactivate acrossai-mcp-manager` — this clears both transients on standard deactivation cleanup (if we ship a `register_deactivation_hook` clearing them, per additive-only contract) or via natural TTL expiry (24h max).

## PR checklist snapshot (Constitution VII DoD)

- [ ] PHPCS: zero errors and zero warnings
- [ ] PHPStan level 8: zero errors
- [ ] ESLint: zero errors
- [ ] Security review: nonce + capability + sanitization + escaping verified at every boundary (see `security-constraints.md` after `/speckit-security-review-plan` runs)
- [ ] PHPUnit passing: `REST/QuickSetupControllerTest`, `Admin/QuickSetup/AdminBarEntryTest`, `Admin/QuickSetup/ActivationRedirectTest`, `Admin/QuickSetup/QuickSetupPageTest`
- [ ] Data input uses DataForm (Step 1 create form, AC editor); Data display uses DataViews (Step 1 picker)
- [ ] No code duplication; AC editor imported not copy-pasted
- [ ] All new symbols prefixed `acrossai_mcp_`
- [ ] `npm run validate-packages` passes
- [ ] Version bump 0.2.10 → 0.2.11 in three files (plugin header, `ACROSSAI_MCP_MANAGER_VERSION` constant, README Stable tag)
- [ ] `= 0.2.11 =` changelog section added to README.txt
- [ ] Additive-only invariant grep clean (see spec § Final full-repo audit)
