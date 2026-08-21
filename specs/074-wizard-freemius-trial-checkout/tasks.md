# Tasks — F074 Wizard Freemius Trial Checkout

**Feature**: [spec.md](./spec.md) · [plan.md](./plan.md)
**Branch**: `074-wizard-freemius-trial-checkout`
**Total**: 6 tasks (3 code + 1 rebuild + 1 verification + 1 commit)

## Phase 1 — Code changes (T001 + T002 same file, T003 independent)

- [x] **T001** Edit `admin/Main.php::maybe_enqueue_quick_setup_app()`. Append a new `freemiusPro` key to the `wp_localize_script( 'acrossai-mcp-manager-quick-setup', 'acrossaiMcpQuickSetup', [ … ] )` payload with three PUBLIC string identifiers: `product_id: '34763'`, `public_key: 'pk_22d5131412bed600815c5b30ae044'`, `plan_id: '60904'`. Reference plan.md → *Per-file change map → `admin/Main.php`*.

- [x] **T002** Same file, same method. Immediately after the `wp_localize_script` call, add `wp_enqueue_script( 'acrossai-mcp-manager-freemius-checkout', 'https://checkout.freemius.com/js/v1/', array(), 'v1', true )`. Same enqueue pattern as `wp-content/plugins/freemius/includes/class-freemius-button.php:78`. Inline comment referencing F074 + the Freemius reference plugin.

- [x] **T003** Edit `src/js/quick-setup/steps/Step8_ProPromo.jsx`:
  1. Import `useState`, `useCallback` from `@wordpress/element`; consume `refetch` from `useWizardState()`.
  2. Add local state `[trialStarted, setTrialStarted] = useState(false)` and a memoised `handleStartTrial` callback:
     - Read `bootstrap.freemiusPro` + `window.FS?.Checkout`.
     - Missing → `window.open( TRIAL_URL, '_blank', 'noopener' )` (fallback).
     - Present → `new window.FS.Checkout({ product_id, public_key }).open({ name: 'AcrossAI Pro', licenses: 1, plan_id, trial: 'free', purchaseCompleted, success, cancel })`.
     - `purchaseCompleted` and `success` flip `trialStarted` to `true`; `success` also calls `refetch()`.
  3. Swap the `<a href={TRIAL_URL} target="_blank">Start free trial</a>` for `<button type="button" onClick={handleStartTrial}>Start free trial</button>`.
  4. Add a conditional `<Notice status="success">` above the marketing card when `trialStarted` is true, with the follow-up instructions ("check your email for the download link, install + activate, return to this page").
  5. Leave the Continue-disabled guard, marketing card layout, and info notice below the card unchanged.

## Phase 2 — Rebuild + Verification

- [x] **T004** `npm run build` — verify no new warnings beyond the 3 pre-existing bundle-size hints on `abilities.js`/`embeds.js`.

- [x] **T005** PHPCS on `admin/Main.php`. Must report zero new violations beyond the pre-existing baseline (4 pre-existing errors on lines 38/45/52/63 predate F074).
  ```
  ./vendor/bin/phpcs admin/Main.php
  ```

- [ ] **T006** Manual smoke check (needs a browser + real Freemius product):
  1. **SC-001** — Open Step 8 with credentials populated. Click "Start free trial". Modal opens over the wizard (matches the user's screenshot).
  2. **SC-002** — Complete the modal trial-start form. Modal closes. Green success `<Notice>` appears above the marketing card. DevTools Network shows `GET /wp-json/acrossai-mcp-manager/v1/quick-setup/state` firing.
  3. **SC-003** — In DevTools Network → right-click `checkout.freemius.com` → Block request domain. Reload Step 8, click the button. Falls back to opening `https://acrossai.co/pricing/#pricing` in a new tab. No JavaScript error logged.
  4. **SC-004** — Confirm PHPCS baseline (T005) is unchanged.

## Phase 3 — Commit (hold on user signal)

- [ ] **T007** `git commit` per plan.md § Branch / commit hygiene. Recommended split:
  1. `feat(quick-setup): F074 code — Freemius trial checkout modal on Step 8` — `admin/Main.php` + `src/js/quick-setup/steps/Step8_ProPromo.jsx` + `build/js/quick-setup*`.
  2. `docs(f074): spec-kit artifacts` — `docs/planings-tasks/074-*.md` + `specs/074-*/`.

  Or fold into a single commit — small-enough scope.

  **Do NOT open a PR** until the user says the word. Pre-existing dirty state on the branch (router sync fix, Free Consultations CTA, their build outputs) stays untouched and unstaged; ask the user how to handle them before pushing.

## Dependency Diagram

```
T001 → T002 (Main.php serialise)
T003 (independent, different file)
     ↓
T004 (rebuild) → T005 (PHPCS) → T006 (manual smoke, needs browser) → T007 (commit)
```
