# Feature 074 — Wizard Freemius Trial Checkout

**Status**: Implemented
**Branch**: `074-wizard-freemius-trial-checkout`
**Date**: 2026-08-21

## Summary

Swap the Quick Setup wizard's Step 8 "Start free trial" CTA from a new-tab external link (to `https://acrossai.co/pricing/#pricing`) to the **Freemius Checkout modal** — the trial-start dialog signs the operator up for the `acrossai-pro` free trial directly over the wizard, no context switch. Uses only the client-side Freemius Buy Button script (`https://checkout.freemius.com/js/v1/`); does NOT reintroduce the Freemius WordPress SDK that F028 retired. Falls back to the pre-F074 external-link behaviour when the CDN script is blocked (ad-blocker, CSP, network failure) so a broken third-party dependency cannot dead-end the wizard.

## User Stories

**US1 — First-run operator wants Pro (top priority)** — As an operator on Step 8 of the wizard, I want to click "Start free trial" and have the Freemius signup dialog appear right over the wizard so I can start my free trial without leaving the setup flow and losing my place.

**US2 — Ad-blocker / CSP-locked operator** — As an operator whose browser blocks `checkout.freemius.com` (aggressive ad-blocker, strict corporate CSP), I want the button to still open the pricing page in a new tab so I'm not stuck on Step 8.

**US3 — Post-trial return** — As an operator who completed the trial signup in the modal, I want the wizard to acknowledge my trial started (visible success message) and auto-refresh so it can walk me forward to Step 9 the moment my Pro plugin is detected.

## Functional Requirements

**FR-001** — MUST extend the wizard's existing `wp_localize_script( 'acrossai-mcp-manager-quick-setup', 'acrossaiMcpQuickSetup', [...] )` call in `admin/Main.php::maybe_enqueue_quick_setup_app()` with a hardcoded `freemiusPro` key containing exactly three string keys: `product_id`, `public_key`, `plan_id`. All three are PUBLIC identifiers per Freemius conventions (safe for WP.org).

**FR-002** — MUST enqueue the Freemius Checkout script (`https://checkout.freemius.com/js/v1/`) via `wp_enqueue_script( 'acrossai-mcp-manager-freemius-checkout', …, [], 'v1', true )` alongside the wizard bundle inside the same `?quick-setup=1` gate — so the script never loads on any other admin surface.

**FR-003** — MUST NOT reintroduce the Freemius WordPress SDK, `fs_dynamic_init()`, `AcrossAI_Addon\` namespace, or any admin submenu / dashboard-notice from Freemius. F028's supersession decisions stay in force.

**FR-004** — Step 8's "Start free trial" CTA MUST become a `<button type="button">` (not `<a>`) whose `onClick` handler calls `new window.FS.Checkout({product_id, public_key}).open({name: 'AcrossAI Pro', licenses: 1, plan_id, trial: 'free', purchaseCompleted, success, cancel})`. Matches the canonical Freemius Buy Button API shape at `wp-content/plugins/freemius/src/button/view.js:37+67`.

**FR-005** — On `purchaseCompleted` OR `success` callback, MUST set a local `trialStarted` flag → render a `<Notice status="success">` above the marketing card with the paste-ready follow-up instructions ("check your email for the download link, install + activate, return to this page").

**FR-006** — On `success`, MUST also call `refetch()` from `useWizardState()` so if Freemius auto-detects the site (e.g. their WP SDK finds a matching trial via email), the wizard's auto-skip effect in `App.jsx` walks the operator forward to Step 9 automatically.

**FR-007** — If EITHER `window.acrossaiMcpQuickSetup.freemiusPro.product_id` is missing OR `window.FS?.Checkout` is not a function (script blocked / not loaded), the click handler MUST silently fall back to `window.open( TRIAL_URL, '_blank', 'noopener' )` — the pre-F074 behaviour. Zero regression risk.

**FR-008** — MUST NOT change Step 8's marketing card content (headline, subtitle, feature bullets, ability count), the Continue-disabled guard, or the info notice below the card. Layout and styling remain byte-identical to pre-F074.

## Success Criteria

**SC-001** — With `freemiusPro` credentials populated AND the Freemius script loaded, clicking "Start free trial" on Step 8 opens the Freemius modal directly over the wizard (matching the screenshot the user provided).

**SC-002** — After the operator completes the trial-start form in the modal, the modal closes, a green success `<Notice>` appears above the marketing card, and DevTools Network shows a follow-up `GET /wp-json/acrossai-mcp-manager/v1/quick-setup/state` firing (the `refetch()` triggered by the `success` callback).

**SC-003** — With `checkout.freemius.com` blocked in DevTools Network → clicking "Start free trial" opens `https://acrossai.co/pricing/#pricing` in a new tab (fallback path). No JavaScript error is logged.

**SC-004** — PHPCS on `admin/Main.php` reports zero new violations beyond the pre-existing baseline (4 pre-existing errors on lines 38/45/52/63 predate F074).

## Out of Scope

- Any changes to Step 9 (Pro Activate) — its footer-action "I've activated it — re-check" from F072 continues to handle plugin activation.
- Bridging the post-modal UX gap (auto-download + install of `acrossai-pro`). The operator follows Freemius's email flow. Deferred to F075+.
- Any WP filter to override the Freemius credentials — hardcoded per user's decision.
- SCSS. Freemius styles its own modal.
- Any composer or npm dependency change.
- Any change to the `wp-content/plugins/acrossai-pro` companion plugin.
- Automated PHPUnit tests for the JS-only click handler.
