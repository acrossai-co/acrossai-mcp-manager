# Quick Setup Wizard — step-by-step walkthrough

Source of truth for step titles: `src/js/quick-setup/StepLayout.jsx:21-36`.
Skip predicates: `src/js/quick-setup/App.jsx:116-148`.
Router: `src/js/quick-setup/hooks/useWizardRouter.js`.

The wizard is a **13-step registry with a dynamic 5-to-10-step visible flow** — every step past 1 has a skip predicate that removes it from the sequence when it isn't needed. The progress bar always shows `displayIndex / totalSteps` computed over the visible subset, so the header never shows "Step 4 of 13" when the user will only see 6 screens.

---

## Always-visible spine (5 steps)

These four render every time, plus one of steps 10-13 at the end.

### Step 1 — Choose a server
File: `steps/Step1_ServerPick.jsx`
Grid of existing MCP servers + a "**+ Create a new server**" tile. Picking an existing server writes `server_id` to the wizard scratchpad; picking the create tile sets `create_intent = true`.

### Step 3 — Choose access
File: `steps/Step3_AccessControl.jsx`
Access-control editor (roles + users + capabilities) for the selected server. Extracted into a reusable AC editor per the F069 phase 3D refactor so it's the same component the admin server-edit screen uses.

### Step 7 — Pick a connection method
File: `steps/Step7_MethodGrid.jsx`
Four tiles:
1. **One-click OAuth (Connectors)** — cloud AI apps (Claude / ChatGPT / Grok / Gemini / Cursor) via the `acrossai-pro` companion.
2. **MCP Client** — IDE / desktop app config-file snippets (all 16 built-in clients: Claude Desktop, Claude Code, VS Code, GitHub Copilot, Codex, Cursor, Gemini, Windsurf, Zed, Cline, Roo Code, Kilo Code, Amazon Q, OpenCode, Antigravity, Custom).
3. **npm (one-line install)** — the `npx -y @acrossai/mcp-manager` CLI installer.
4. **WP-CLI (local subprocess)** — subprocess bridge for server-side agents.
Picking a tile writes `method` to the scratchpad, which unlocks exactly ONE of steps 10-13.

### Steps 10-13 — Method detail (exactly one renders)
Each of these is the terminal step for one method — the wizard's Continue button becomes **Finish** here.
- **Step 10 — One-click OAuth setup** (`Step10_ConnectorsDetail.jsx`) — renders when `method = connectors` AND acrossai-pro is `active`. Deep-link into the Connectors tab.
- **Step 11 — MCP Client setup** (`Step11_ClientDetail.jsx`) — renders when `method = client`. Sub-nav with all 16 clients + JSON snippet + "copy" + config-file hint.
- **Step 12 — npm setup** (`Step12_NpmDetail.jsx`) — renders when `method = npm`. One-line `npx` command + auth-token block.
- **Step 13 — WP-CLI setup** (`Step13_WpCliDetail.jsx`) — renders when `method = wpcli`. `wp acrossai mcp` invocation + subprocess wiring notes.

---

## Conditional steps (0-5 additional)

These appear only when their predicate is TRUE. Predicates live in `App.jsx:116-148`.

### Step 2 — Create a new server
File: `steps/Step2_ServerCreate.jsx`
Predicate: `server_id === null AND create_intent === true` (user clicked the "+ Create a new server" tile in Step 1). Skipped when picking an existing server. Continue button lives in StepLayout, not the step — Step 2 uses `useAdvanceGuard` to validate + POST the create before allowing advance.

### Step 4 — Enable Abilities Manager
File: `steps/Step4_AbilitiesManager.jsx`
Predicate: the `abilities-manager` plugin is NOT active. Gate step — user is expected to activate/install the companion plugin, then hit Refresh. Full-screen loading overlay renders during the plugin state refetch.

### Step 5 — Enable abilities
File: `steps/Step5_Abilities.jsx`
Predicate: at least one ability exists AND at least one is still disabled for this server. Skipped when every ability is already on. Grid of ability toggles with an "Enable all and continue" bulk action.

### Step 6 — Enable server
File: `steps/Step6_EnableServer.jsx`
Predicate: the selected server is NOT already enabled in the DB. Reads from `state.servers` (authoritative) rather than the scratchpad flag so a pre-existing enabled server is correctly skipped even on the first wizard run.

### Step 8 — Get AcrossAI Pro
File: `steps/Step8_ProPromo.jsx`
Predicate: `method = connectors` AND acrossai-pro is `missing`. Marketing pitch + install link.

### Step 9 — Activate AcrossAI Pro
File: `steps/Step9_ProActivate.jsx`
Predicate: `method = connectors` AND acrossai-pro is `inactive` (installed but not activated). One-click activate button + refetch loop until state flips to `active`.

---

## Completion screen

Not a step — sits outside the numbered registry.

### `done` — You're all set!
File: `steps/Completion.jsx`
Fires when Continue is pressed on step 10, 11, 12, or 13 (the four terminal steps identified by `isTerminalStep = ['10','11','12','13'].includes(router.step)`). Also POSTs `/complete` to close out the wizard scratchpad.

---

## Example visible flows

**Best case (existing enabled server, abilities all on, Pro active, picks Connectors) — 5 screens:**
`1 → 3 → 7 → 10 → done`

**New-server / OAuth path — 8 screens:**
`1 → 2 → 3 → 5 → 6 → 7 → 10 → done`

**New-server / npm path with abilities-manager missing — 8 screens:**
`1 → 2 → 3 → 4 → 5 → 6 → 7 → 12 → done`

**Everything-missing worst case (Pro missing) — 9 screens:**
`1 → 2 → 3 → 4 → 5 → 6 → 7 → 8 → done` (8 is a gate — user installs Pro then re-runs)

Progress-bar denominator in each row = total dots in that arrow chain.
