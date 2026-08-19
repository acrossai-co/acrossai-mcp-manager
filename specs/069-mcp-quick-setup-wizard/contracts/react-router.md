# Contract: React URL router (client-side)

Wizard step + method state lives in the URL query string. Browser Back/Forward walks steps naturally; deep-links + reload restore position without full page loads.

## URL shape

| Segment | Values | Set by | Consumed by |
|---|---|---|---|
| `page` | `acrossai_mcp_manager` | WP admin | plugin page dispatcher |
| `quick-setup` | `1` | admin bar chip / activation redirect / deep link | `Settings::render()` hijack + `admin/Main::enqueue_scripts()` gate |
| `step` | `1` \| `2` \| `3` \| `4` \| `5` \| `done` | wizard-side `useWizardRouter` on advance/back | `App.jsx` — dispatches to the correct step component |
| `method` | `connectors` \| `client` \| `npm` \| `wpcli` (optional; only meaningful on step 5) | Step 5 method card click | `Step5_MethodGrid` — expands the matching panel |
| `first_run` | `1` (optional; sent only by activation redirect) | `ActivationRedirect::maybe_redirect()` | reserved for future onboarding analytics — no wizard behavior change today |

**Canonical URL examples**:

```
/wp-admin/admin.php?page=acrossai_mcp_manager&quick-setup=1&step=1
/wp-admin/admin.php?page=acrossai_mcp_manager&quick-setup=1&step=5&method=client
/wp-admin/admin.php?page=acrossai_mcp_manager&quick-setup=1&step=done
/wp-admin/admin.php?page=acrossai_mcp_manager&quick-setup=1&step=1&first_run=1
```

## `useWizardRouter` hook — public API

```javascript
const {
  step,       // '1' | '2' | '3' | '4' | '5' | 'done'
  method,     // 'connectors' | 'client' | 'npm' | 'wpcli' | null
  goTo,       // (step, method?) => void — direct jump; guards enforced by App.jsx
  advance,    // () => void — step + 1 (or skips step 4 if server already enabled)
  back,       // () => void — step - 1 (mirrors auto-skip on step 4)
  exit,       // () => void — navigates to state.adminUrl (no quick-setup=1)
} = useWizardRouter();
```

**Contract details**:

- `step` and `method` are strings (query params are always strings in WP `addQueryArgs`).
- `goTo(step)` clears the `method` param; `goTo(step, method)` sets both.
- `advance()` respects the auto-skip rule: if `wizardState.enabled === true` and current step is 3, `advance()` jumps to 5 (skipping 4). Symmetric for `back()`.
- `exit()` navigates to `window.acrossaiMcpQuickSetup.adminUrl` (the plugin's list-table page).

## History integration

- Every `goTo` / `advance` / `back` fires `history.pushState({}, '', newUrl)` via `@wordpress/url` `addQueryArgs`.
- Hook registers a single `popstate` listener on mount, unregisters on unmount. When `popstate` fires, hook re-reads `step` + `method` from `window.location.search` via `getQueryArg` and updates React state.
- **No full page reload** in normal wizard operation.

## Step guard contract

Each step component exposes a `canAdvance` boolean returned via a shared React context (`WizardGuardContext`). `App.jsx` disables the Continue button when `canAdvance === false`.

| Step | `canAdvance` when |
|---|---|
| 1 | `wizardState.server_id !== null` |
| 2 | always true (AC rule is optional — spec FR-014 explicitly permits advancing without change) |
| 3 | always true (abilities enable is optional) |
| 4 | `wizardState.enabled === true` (toggle must be on) |
| 5 | `wizardState.method !== null` (must pick a card) |
| done | Continue button hidden (spec FR-024) |

## Deep-link fallback

When a user lands directly on `?step=5&method=client` without valid preceding state:

- `App.jsx` on mount reads `wizardState` from `GET /quick-setup/state`.
- If the target step's precondition is unmet (e.g., step 5 requires `server_id`; scratchpad has none), the hook silently `goTo(furthestLegitimateStep)` — landing the user on step 1 if nothing is set, step 2 if only server picked, etc.
- This is a silent redirect (no user-visible error) per spec FR (User Story 5, Acceptance Scenario 2).

## Accessibility contract (WCAG 2.1 AA — FR-010a)

- On every URL-driven step change:
  1. `StepLayout` moves keyboard focus to the first focusable element of the incoming step (via `useEffect` + `ref.current.focus()`).
  2. `StepLayout` emits the new step name into an `aria-live="polite"` region: `"Step {N} of {total}, {step-name}"`.
- Progress bar carries `role="progressbar"`, `aria-valuenow={currentIndex}`, `aria-valuemin={1}`, `aria-valuemax={totalSteps}`.
- Continue button disabled state uses `aria-disabled="true"` (never `disabled` attribute alone) so screen readers announce the reason.

## Router-level tests (target)

- `useWizardRouter` MUST be unit-testable via a jsdom test that stubs `window.location` + `window.history` + `dispatchEvent(new PopStateEvent('popstate'))`. (No Jest infra in the plugin today — deferred to a follow-up if the router grows complex; manual test plan below covers it.)

**Manual acceptance test plan** (covered in `quickstart.md`):

1. Load `?quick-setup=1` (no `step`) → wizard defaults to step 1.
2. Click Continue → URL updates to `?step=2` without reload; scratchpad persists via REST.
3. Browser Back → URL returns to `?step=1`; UI syncs (popstate).
4. Reload → wizard renders step 1 with prior state hydrated from scratchpad.
5. Direct-nav to `?step=5&method=client` with fresh session → silent redirect to `?step=1`.
6. Complete wizard → URL becomes `?step=done`; Finish button hidden; Dismiss link exits to plugin page (`admin.php?page=acrossai_mcp_manager`, no `quick-setup=1`).
