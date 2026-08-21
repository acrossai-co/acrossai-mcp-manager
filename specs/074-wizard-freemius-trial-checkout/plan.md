# Implementation Plan — F074 Wizard Freemius Trial Checkout

**Branch**: `074-wizard-freemius-trial-checkout` | **Date**: 2026-08-21 | **Spec**: [spec.md](./spec.md)

## Summary

Small, contained UX change on Step 8 only. Two files touched: `admin/Main.php` (add `freemiusPro` bootstrap key + enqueue Freemius Checkout script) and `src/js/quick-setup/steps/Step8_ProPromo.jsx` (swap CTA `<a>` for a `<button>` invoking `new FS.Checkout(...).open(...)`). No new REST route, no new PHP class, no new React component, no SCSS, no composer dep. Falls back to the current external-link behaviour when the Freemius CDN script fails to load. Does NOT reintroduce the Freemius WordPress SDK.

## Technical Context

**Language/Version**: PHP 8.1+ (plugin baseline). ES2022 / React 18 (@wordpress/scripts).
**Primary Dependencies**: `https://checkout.freemius.com/js/v1/` (loaded via `wp_enqueue_script`, no bundled copy).
**Storage**: none — no DB, no options, no transients.
**Testing**: PHPCS + manual smoke (modal opens, fallback triggers, success notice + refetch fire).
**Constraints**: Additive only. Missing-credentials + script-load-failure both silently fall back to pre-F074 external-link behaviour. Zero regression risk.
**Scale/Scope**: ~50 LOC net addition across 2 files.

## Constitution Check

*All principles pass.*

**I. Modular Architecture** — Extensions land in existing methods (`Main::maybe_enqueue_quick_setup_app`, `Step8_ProPromo`). No new files.

**II. Additive** — `freemiusPro` bootstrap key is additive; existing consumers ignoring it stay functional. `wp_enqueue_script` for the Freemius CDN loads only inside the wizard's `?quick-setup=1` gate. `<a>` → `<button>` swap preserves the fallback external-link path.

**III. Security** — Only PUBLIC identifiers shipped (Freemius `product_id`, `public_key`, `plan_id` — same class of value as Stripe `pk_live_*`). Third-party script origin is `https://checkout.freemius.com` (Freemius's own CDN). No user input reflected. No REST callback introduced.

**IV. UI Components** — No React component API changes. Reuses `Notice`. Zero SCSS.

**V. Extensibility** — No new hooks / filters introduced. Credentials are hardcoded per user decision.

**VI. DRY** — `TRIAL_URL` constant reused for the fallback; enqueue pattern mirrors the canonical Freemius Buy Button block.

**VII. Tests First** — No branching logic worth unit-testing (the click handler is pure I/O to the Freemius global). PHPCS gates PHP; manual smoke covers the four SCs.

## Constitution-adjacent memory guidance

- **F028 supersession** — this feature explicitly does NOT resurrect the Freemius WordPress SDK. `DEC-FREEMIUS-DOUBLE-OPTIN-GATES-ACCOUNT`, `DEC-ADDONS-PAGE-VENDOR-CTOR-BOOT`, and `B28` all remain Superseded (F028). F074 uses only the client-side Buy Button script, which is a distinct primitive.
- **A1 hook registration** — no new `add_action`/`add_filter`. `wp_enqueue_script` is called inside the existing `maybe_enqueue_quick_setup_app()` method that's already wired via `Includes\Main::define_admin_hooks()`.

## Project Structure

### Documentation (this feature)

```
specs/074-wizard-freemius-trial-checkout/
├── spec.md
├── plan.md
└── tasks.md
```

### Source Code Changes

```
admin/Main.php                                        # MODIFIED — maybe_enqueue_quick_setup_app() extended
src/js/quick-setup/steps/Step8_ProPromo.jsx           # MODIFIED — CTA behaviour swapped
build/js/quick-setup*                                 # REBUILT — @wordpress/scripts output
```

No new files. No deletions.

## Per-file change map

### `admin/Main.php::maybe_enqueue_quick_setup_app()`

Two additions to the existing method (both after the existing wizard bundle `wp_enqueue_script` + `wp_enqueue_style` calls):

1. New key on the `wp_localize_script` payload:

```php
'freemiusPro' => array(
    'product_id' => '34763',
    'public_key' => 'pk_22d5131412bed600815c5b30ae044',
    'plan_id'    => '60904',
),
```

2. New `wp_enqueue_script` for the Freemius CDN, immediately after the `wp_localize_script` call:

```php
wp_enqueue_script(
    'acrossai-mcp-manager-freemius-checkout',
    'https://checkout.freemius.com/js/v1/',
    array(),
    'v1',
    true
);
```

Both additions carry inline comments referencing this feature (F074) + the Freemius reference plugin (`wp-content/plugins/freemius/includes/class-freemius-button.php:78`).

### `src/js/quick-setup/steps/Step8_ProPromo.jsx`

Three changes:

1. Import additions: `useState`, `useCallback` from `@wordpress/element`. Consume `refetch` from `useWizardState()`.
2. Add local state `[trialStarted, setTrialStarted] = useState(false)` and a memoised `handleStartTrial` callback that:
   - Reads `bootstrap.freemiusPro` and `window.FS?.Checkout`.
   - Missing → `window.open(TRIAL_URL, '_blank', 'noopener')` fallback.
   - Present → `new FS.Checkout({product_id, public_key}).open({name, licenses: 1, plan_id, trial: 'free', purchaseCompleted, success, cancel})`.
   - `purchaseCompleted` + `success` both flip `trialStarted` to `true`. `success` also calls `refetch()`.
3. Swap the `<a href="…" target="_blank">Start free trial</a>` for `<button type="button" onClick={handleStartTrial}>Start free trial</button>` and add a conditional `<Notice status="success">` above the marketing card when `trialStarted` is true.

Continue-disabled guard (`useAdvanceGuard(false)`) unchanged. Marketing card layout unchanged.

## Migration Concerns

None. Additive JS callback + additive PHP payload key + additive `wp_enqueue_script`. Operators upgrading from a pre-F074 version see the new modal on their next Step 8 visit; if the Freemius CDN is unreachable they see the same external-link behaviour as before.

## Rollback

Revert both modified files + rebuild. `wp_enqueue_script` for Freemius disappears; `freemiusPro` bootstrap key gets pruned; Step 8's button reverts to an anchor. No side effects.

## Branch / commit hygiene

`074-wizard-freemius-trial-checkout` cut from origin/main (post PR #85). Two commits recommended:
1. `feat(quick-setup): F074 code — Freemius trial checkout modal on Step 8` — the two source files + build artefacts.
2. `docs(f074): spec-kit artifacts` — planning doc + spec/plan/tasks.

Or fold into one commit — small-enough scope.

**Pre-existing dirty state on the branch (not F074):**
- `src/js/quick-setup/hooks/useWizardRouter.js` — the router cross-instance sync fix (from "Set up another server" bug).
- `src/js/quick-setup/StepLayout.jsx` — the Free Consultations header CTA.
- `src/scss/quick-setup.scss` — SCSS for the Free Consultations CTA.
- `build/js/quick-setup*` — build outputs reflecting both the above + F074.

These four changes are follow-ups from earlier in the session, not F074. They can either ride along in a third commit on this branch or be split into a separate hotfix branch — reviewer's choice. Committing only F074 files (via specific `git add <path>`) keeps them dirty for a later decision.
