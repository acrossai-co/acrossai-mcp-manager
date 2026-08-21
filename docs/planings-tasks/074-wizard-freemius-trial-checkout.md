# Planning: Wizard Freemius Trial Checkout (Feature 074)

Step 8 of the Quick Setup wizard (`?…&quick-setup=1&step=8`) promotes the paid companion plugin `acrossai-pro` with a "Start free trial" button. Pre-F074 the button opened `https://acrossai.co/pricing/#pricing` in a new tab — the operator left the wizard, completed checkout on the marketing site, and had to navigate back and reload to continue. F074 swaps that behaviour for the **Freemius Checkout modal** that pops up directly over the wizard, so the trial signup happens inline without abandoning the flow.

## Authoritative sources

- Spec: [`specs/074-wizard-freemius-trial-checkout/spec.md`](../../specs/074-wizard-freemius-trial-checkout/spec.md)
- Plan: [`specs/074-wizard-freemius-trial-checkout/plan.md`](../../specs/074-wizard-freemius-trial-checkout/plan.md)
- Tasks: [`specs/074-wizard-freemius-trial-checkout/tasks.md`](../../specs/074-wizard-freemius-trial-checkout/tasks.md)

## Key architectural picture

F074 uses only the **client-side Freemius Buy Button** — a single `<script src="https://checkout.freemius.com/js/v1/">` + a JS API call (`new FS.Checkout({product_id, public_key}).open({plan_id, trial: 'free', …})`). This is a completely separate primitive from the Freemius WordPress SDK that F028 (2026-07-17) deliberately retired. All three superseded decisions from F028 (`DEC-FREEMIUS-DOUBLE-OPTIN-GATES-ACCOUNT`, `DEC-ADDONS-PAGE-VENDOR-CTOR-BOOT`, `B28`) STAY superseded — F074 does not resurrect any of them.

**No composer dep, no `fs_dynamic_init`, no `AcrossAI_Addon\` namespace, no admin submenus, no double-opt-in email round-trip, no `is_registered()` gating.**

## Final scope

Retained:
- `admin/Main.php::maybe_enqueue_quick_setup_app()` extended with:
  - New `freemiusPro` key in the wizard's `wp_localize_script` payload (three PUBLIC identifiers hardcoded).
  - `wp_enqueue_script( 'acrossai-mcp-manager-freemius-checkout', 'https://checkout.freemius.com/js/v1/', [], 'v1', true )` alongside the wizard bundle enqueue. Same enqueue pattern as the Freemius plugin's own Buy Button block (`wp-content/plugins/freemius/includes/class-freemius-button.php:78`).
- `src/js/quick-setup/steps/Step8_ProPromo.jsx` — swap the anchor CTA (`<a href="https://acrossai.co/pricing/#pricing" target="_blank">`) for a `<button>` whose `onClick` calls `new window.FS.Checkout({product_id, public_key}).open({plan_id, trial: 'free', purchaseCompleted, success, cancel})`. On success, flip a local `trialStarted` flag → render a `<Notice status="success">Trial started — check your email for the download link</Notice>` above the marketing card, and call `refetch()` so the wizard's auto-skip effect walks the operator forward if Freemius auto-detects the site (e.g. via matching email).
- Missing-credentials OR blocked-script fallback: `window.open( TRIAL_URL, '_blank', 'noopener' )` — matches the pre-F074 behaviour. Zero regression risk.

Not in scope:
- **NO reintroduction of the Freemius WordPress SDK.** F028 supersession decisions stay in force.
- No new REST route, no new PHP class, no new singleton, no admin surface change.
- No SCSS. Freemius styles its own modal.
- No new composer dependency.
- No changes to Step 9 (Pro Activate) — its "I've activated it — re-check" flow (from F072 follow-up) continues to handle plugin activation after trial signup.
- Bridging the post-modal UX gap (auto-download + auto-install of `acrossai-pro` after trial signup) is deliberately deferred to F075+. The operator follows Freemius's email flow for step 1 (download) and step 3 (licence entry); Step 9's re-check handles step 2 (activation).

## Durable lesson

**Client-side third-party Buy Button ≠ third-party SDK.** F028 retired the Freemius WordPress SDK for admin-surface complexity (double-opt-in gates, submenu injection, `is_registered()` shape changes). F074 uses the same vendor's checkout JS script — but as a plain `wp_enqueue_script` + tiny React handler. No admin footprint, no composer coupling, no SDK boot lifecycle. When a previous "vendor SDK retirement" decision comes up in review, distinguish between the *heavy vendor integration* (SDK, PHP boot, admin UI) and the *lightweight vendor client* (JS script, iframe embed, REST callback) — the second is often welcome even when the first was cut.

Applies to any future decision to add / remove vendor code from this plugin: articulate which layer of the vendor's product you're using, not just the vendor's name.

## Reference code

The canonical Freemius Buy Button pattern we're following (from their own plugin at `wp-content/plugins/freemius/src/button/view.js:37+67`):

```js
const handler = new FS.Checkout( { product_id } );
handler.open( freemius_copy );
```

Our F074 use at `src/js/quick-setup/steps/Step8_ProPromo.jsx`:

```jsx
const handler = new window.FS.Checkout( { product_id, public_key } );
handler.open( {
    name: 'AcrossAI Pro',
    licenses: 1,
    plan_id,
    trial: 'free',
    purchaseCompleted: () => setTrialStarted( true ),
    success: () => { setTrialStarted( true ); refetch(); },
    cancel: () => { /* no-op */ },
} );
```

Added: 2026-08-21 on branch `074-wizard-freemius-trial-checkout`.
