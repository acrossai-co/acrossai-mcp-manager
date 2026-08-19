# Contract: Client-side wizard state store

Client-side state is managed with `useReducer` + React Context (see `research.md` R3). No `@wordpress/data` store; no external state libs.

## Store shape

```javascript
const initialState = {
  status: 'idle',          // 'idle' | 'loading' | 'ready' | 'saving' | 'error'
  error: null,             // { code, message } | null

  // Snapshot from GET /quick-setup/state
  servers: [],             // MCPServer DTOs
  abilities: {
    total: 0,
    hasManagerPlugin: false,
  },
  plugins: {
    acrossaiPro: 'missing',      // 'missing' | 'inactive' | 'active'
    abilitiesManager: 'missing',
  },

  // In-flight wizard answers (mirrors server-side scratchpad)
  wizardState: {
    current_step: 1,       // int; 'done' for completion
    server_id: null,
    access_saved: false,
    abilities_saved: false,
    enabled: false,
    method: null,
    created_at: null,
  },
};
```

## Reducer actions

| Action type | Payload | Effect |
|---|---|---|
| `HYDRATE_START` | — | `status = 'loading'` |
| `HYDRATE_SUCCESS` | full `GET /state` response | merge into state; `status = 'ready'` |
| `HYDRATE_ERROR` | `{ code, message }` | `status = 'error'`; `error = payload` |
| `SAVE_STEP_START` | `{ step, data }` | `status = 'saving'`; optimistic scratchpad update |
| `SAVE_STEP_SUCCESS` | `POST /step` response | merge `wizardState` + optional `servers`; `status = 'ready'` |
| `SAVE_STEP_ERROR` | `{ code, message, rollbackState }` | revert optimistic update; `status = 'error'`; `error = payload` |
| `COMPLETE_START` | — | `status = 'saving'` |
| `COMPLETE_SUCCESS` | — | `wizardState = initialState.wizardState`; `status = 'ready'` |

## Provider + hook API

```javascript
// App.jsx wraps children:
<WizardStateProvider>
  <StepLayout>{stepComponent}</StepLayout>
</WizardStateProvider>

// Any component in the tree consumes:
const {
  state,       // full store snapshot (read-only)
  isLoading,   // status === 'loading' || 'saving'
  error,       // state.error passthrough
  refetch,     // () => Promise — re-hits GET /state
  saveStep,    // (step, data) => Promise<responseState>
  complete,    // () => Promise<void>
} = useWizardState();
```

## Optimistic update rule

`saveStep` writes the delta to `state.wizardState` immediately (before the REST round-trip completes). On response:
- Success → merge server response (may include newly-created `server_id`, refreshed `servers[]`).
- Failure → dispatch `SAVE_STEP_ERROR` with the pre-write snapshot as `rollbackState`; reducer reverts.

Rationale: keeps Continue button responsive (< 100ms perceived latency) while the REST call runs. Matches F017/F020/F037 patterns.

## Guard-context contract

Each step component MAY set its `canAdvance` boolean via a companion context:

```javascript
// Inside a step:
useAdvanceGuard(wizardState.server_id !== null);
```

`App.jsx` reads the current step's guard and drives the Continue button's `disabled` + `aria-disabled` state.

## Interaction with REST

- **On mount**: `App.jsx` fires `HYDRATE_START` → `apiFetch({ path: '/acrossai-mcp-manager/v1/quick-setup/state' })` → `HYDRATE_SUCCESS` or `HYDRATE_ERROR`.
- **On step advance**: current step's Continue handler calls `saveStep(currentStep, delta)` → on success, `useWizardRouter().advance()` → URL update.
- **On completion**: `Step5` Finish handler calls `complete()` → on success, `useWizardRouter().goTo('done')`.

## Error surfacing

`Notice` component (see `contracts/react-router.md` shared components) renders `state.error.message` at the top of the current step's content pane when `status === 'error'`. Non-blocking dismissal via a close button that dispatches `HYDRATE_SUCCESS` with the last-good snapshot (clears the error without re-fetching).

## What this contract does NOT specify

- **CSS module structure**: covered separately in `src/scss/quick-setup.scss` (see `plan.md` § Project Structure).
- **Icon SVG sources**: covered inline in `components/icons.jsx`.
- **Copy-to-clipboard behavior**: covered by `@wordpress/compose` `useCopyToClipboard` — no wizard-specific contract; use the WP hook directly.
- **Focus-management + ARIA live region behavior**: covered in `contracts/react-router.md` § Accessibility contract.
