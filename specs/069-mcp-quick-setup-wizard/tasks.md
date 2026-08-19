---

description: "Task list for Feature 069 — MCP Quick Setup Wizard"

---

# Tasks: MCP Quick Setup Wizard

**Input**: Design documents from `specs/069-mcp-quick-setup-wizard/`
**Prerequisites**: `plan.md`, `spec.md`, `research.md`, `data-model.md`, `contracts/`, `quickstart.md`, `security-constraints.md`, `memory-synthesis.md` — all present in `FEATURE_DIR`

**Tests**: Included. Spec §Success Criteria + Constitution §VII DoD both gate on PHPUnit. Test tasks land alongside implementation per phase, not as a separate deferred phase.

**Organization**: Tasks are grouped by user story. Phase 3 (US1 — first-run redirect + full end-to-end) is the MVP; every other user story adds a smaller, independently testable slice on top.

**Path Conventions**: WordPress plugin single-project layout per `plan.md` § Project Structure. All source paths relative to plugin root `wp-content/plugins/acrossai-mcp-manager/`.

**Security folds**: Findings SEC-001 through SEC-005 from `security-constraints.md` land as tasks `TASK-SEC-001` through `TASK-SEC-005` at the appropriate phase — see the security-review cross-reference table at the bottom.

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Wire the new asset entry into the build pipeline and create empty directory scaffolding so subsequent tasks can drop files in place.

- [x] T001 Add `js/quick-setup` entry to `webpack.config.js` alongside `js/embeds` / `js/abilities` / `js/tools` — `'js/quick-setup': path.resolve( process.cwd(), 'src/js', 'quick-setup.js' )`
- [x] T002 [P] Create empty directories `admin/Partials/QuickSetup/`, `includes/REST/` (already exists — noop guard), `src/js/quick-setup/{hooks,components,steps}/`, `assets/quick-setup/`, `tests/phpunit/Admin/QuickSetup/`, `tests/phpunit/REST/`
- [x] T003 Verify `@wordpress/dataviews` is present in `@wordpress/scripts` externals map on the plugin's Node modules; document result in `research.md` R2 (already resolved; this task is the CI-time verification that closes the memory soft-conflict B22). Run `npm ls @wordpress/dataviews` in the plugin root and confirm resolution.

**Checkpoint**: Build pipeline recognises the new entry; empty scaffolding present.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Shared components, hooks, styles, asset, and the shared sanitizer helper — every user story below depends on these landing first.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [x] T004 [P] Author SCSS design tokens + BEM-lite base styles in `src/scss/quick-setup.scss`. Include all `$qs-*` variables from `plan.md` § Project Structure + a `.acrossai-mcp-quick-setup-wrap` root scope so styles never leak. Import from `src/js/quick-setup.js` so `mini-css-extract` emits `build/js/quick-setup.css` (matches F037 shape).
- [x] T005 [P] Copy AcrossAI logo SVG from Claude Design project `135ba973-6a42-48b5-9d50-8f79ddaaaa7f` file `acrossai-logo.svg` to `assets/quick-setup/acrossai-logo.svg`. Verify `viewBox="0 0 100 100"` per `plan.md` TASK-11 note.
- [x] T006 [P] Implement inline SVG icon components in `src/js/quick-setup/components/icons.jsx`: `LinkIcon`, `PuzzleIcon`, `TerminalIcon`, `CheckIcon`, `ChevronDown`, `ChevronRight`, `ExternalLinkArrow`. No external icon library (per `plan.md` Technical Context).
- [x] T007 [P] Implement `Notice.jsx` in `src/js/quick-setup/components/Notice.jsx` — props `{ status: 'info'|'warning'|'success'|'error', children }`, left-color-bar per `contracts/wizard-state.md` §Error surfacing.
- [x] T008 [P] Implement `CodeBlock.jsx` in `src/js/quick-setup/components/CodeBlock.jsx` — variants `'inline'` + `'pane'`, uses `@wordpress/compose` `useCopyToClipboard`. **MUST render content as text node children of `<code>`/`<pre>` — no `dangerouslySetInnerHTML`** (TASK-SEC-004 constraint below).
- [x] T009 [P] Implement `RadioCard.jsx` in `src/js/quick-setup/components/RadioCard.jsx` — server row + method card wrapper, props `{ selected, onSelect, title, subtitle, badge?, children? }`. Keyboard-accessible: role="radio", `aria-checked`, focusable with tab.
- [x] T010 Implement `useWizardRouter.js` hook in `src/js/quick-setup/hooks/useWizardRouter.js` per `contracts/react-router.md` — returns `{ step, method, goTo, advance, back, exit }`; uses `@wordpress/url` `getQueryArg` + `addQueryArgs`; registers `popstate` listener with unmount cleanup.
- [x] T011 Implement `useWizardState.js` hook + `WizardStateProvider` context in `src/js/quick-setup/hooks/useWizardState.js` per `contracts/wizard-state.md` — `useReducer` + Context; exposes `{ state, isLoading, error, refetch, saveStep, complete }`; optimistic-update on `saveStep`, rollback on error.
- [x] T012 **TASK-SEC-001 + REF-001 (TASK-SEC-T-001)** — Grep the plugin's existing new-server admin form handler (`admin/Partials/Settings.php` or wherever `MCPServerQuery::add_item()` is currently called from the admin side) for its exact sanitizer sequence per field. Extract into `includes/Utilities/MCPServerFieldSanitizer.php` (new class, singleton per plugin convention, single public static `sanitize(array $raw): array` method). **The helper MUST filter its input array against a hard-coded whitelist of exactly 6 keys (`server_name`, `server_slug`, `description`, `server_route_namespace`, `server_route`, `server_version`) BEFORE per-field sanitization runs. Any additional keys MUST be silently dropped — this is the primary defense against B7 mass-assignment via forged POST keys (`is_enabled`, `id`, `created_at`, etc.) reaching `$wpdb->insert()`.** Refactor the EXISTING admin form to call the shared helper. Reference: `security-constraints.md` SEC-001 + SEC-T-001 + memory pattern B7.
- [x] T013 [P] PHPUnit test `tests/phpunit/Utilities/MCPServerFieldSanitizerTest.php` — asserts identical sanitized output for a shared **13-input** fixture set (Unicode, HTML tags, SQL injection strings, over-length inputs, mixed-case slugs, empty strings, whitespace-only, embedded null bytes, right-to-left overrides, emoji, path traversal, script tag, **AND ONE MASS-ASSIGNMENT NEGATIVE CASE**: input `{ 'server_name' => 'foo', 'is_enabled' => 1, 'id' => 999, 'created_at' => '2020-01-01' }` MUST produce output containing ONLY `server_name` populated — every forged key MUST be dropped by the whitelist filter). Reference: `security-constraints.md` SEC-T-001 negative test.

**Checkpoint**: Shared React scaffolding present; shared PHP sanitizer helper present + parity-tested. Every user story phase can now proceed in parallel where feasible.

---

## Phase 3: User Story 1 — First-time admin completes end-to-end setup (Priority: P1) 🎯 MVP

**Goal**: An admin activating the plugin lands directly on the wizard, completes all 5 steps, and reaches the completion screen with all four summary rows populated.

**Independent Test**: Fresh plugin activation on a clean install (no existing rules, no Abilities Manager, no AcrossAI Pro) → verify browser redirects to `?step=1` → walk all steps accepting defaults → completion screen shows valid summary + working "Go to server dashboard" link.

### Backend (PHP)

- [x] T014 [US1] Extend `acrossai_mcp_manager_activate()` in `acrossai-mcp-manager.php` to set the site-level transient `acrossai_mcp_manager_quick_setup_do_redirect` = `'1'` with 30-second TTL after the existing vendor-autoload guard has passed. Wrap in `function_exists('set_transient')` defence per `research.md` R4.
- [x] T015 [US1] Implement `admin/Partials/QuickSetup/ActivationRedirect.php` — singleton per plugin convention (protected `$_instance`, `instance()`, private `__construct()`). Public method `maybe_redirect()` bound to `admin_init` @ priority 5. Guards per `research.md` R4: (a) delete transient FIRST (idempotency), (b) `isset($_GET['activate-multi']) === false` (bulk-activation), (c) `is_network_admin() === false || is_plugin_active_for_network(<plugin_file>) === false` (network-activation), (d) `current_user_can('manage_options')`. Success → `wp_safe_redirect(admin_url('admin.php?page=acrossai_mcp_manager&quick-setup=1&step=1&first_run=1')) . exit`.
- [x] T016 [US1] Implement `admin/Partials/QuickSetup/QuickSetupPage.php` — singleton; single public method `render()` that emits `<div class="wrap acrossai-mcp-quick-setup-wrap"><div id="acrossai-mcp-quick-setup-root"></div><noscript>…</noscript></div>` per `plan.md` TASK-2 snippet.
- [x] T017 [US1] Intercept the existing plugin-page render dispatcher in `admin/Partials/Settings.php` (or `Menu.php` — verify with grep for `add_menu_page` / `render_settings_page`). Add early exit: `if ( !empty($_GET['quick-setup']) && '1' === (string) $_GET['quick-setup'] ) { if ( !current_user_can('manage_options') ) wp_die(esc_html__('You do not have permission to run the Quick Setup wizard.', 'acrossai-mcp-manager')); QuickSetupPage::instance()->render(); return; }` per `research.md` R5.
- [x] T018 [US1] Extend `admin/Main.php` — inside `enqueue_scripts()` and `enqueue_styles()`, add wizard-URL-gated enqueue block: `if ( empty($_GET['quick-setup']) || '1' !== (string) $_GET['quick-setup'] ) return;` early exit for the wizard block; `wp_enqueue_script('acrossai-mcp-manager-quick-setup', …, $asset['dependencies'], $asset['version'], ['in_footer' => true])`; `wp_set_script_translations(…)`; CSS enqueue with `file_exists()` guard; `wp_localize_script('acrossai-mcp-manager-quick-setup', 'acrossaiMcpQuickSetup', [ 'restUrl' => rest_url('acrossai-mcp-manager/v1/quick-setup'), 'restNonce' => wp_create_nonce('wp_rest'), 'adminUrl' => admin_url('admin.php?page=acrossai_mcp_manager'), 'logoUrl' => ACROSSAI_MCP_MANAGER_PLUGIN_URL . 'assets/quick-setup/acrossai-logo.svg' ])`.
- [x] T019 [US1] Implement `includes/REST/QuickSetupController.php` — singleton; `register_routes()` hooked on `rest_api_init` (via Main.php wiring). Register the 3 routes per `contracts/quick-setup-rest.md`. All three: `permission_callback` returns `current_user_can('manage_options')` boolean (S2); POSTs additionally validate `X-WP-Nonce` for action `wp_rest`. Handlers: `handle_state()`, `handle_step()`, `handle_complete()`. **Error-hygiene constraint (TASK-SEC-003 / T055)**: every `WP_Error` returned by any handler in this file MUST use a hand-authored, user-facing message. Raw `$e->getMessage()`, `$wpdb->last_error`, transient key strings, or file paths MUST NEVER appear in the response `message` field. Internal diagnostics MAY be logged via `error_log()` — never surfaced to the client.
- [x] T020 [US1] **Inherits TASK-SEC-003 error-hygiene constraint from T019.** Inside `QuickSetupController::handle_state()` — assemble the full snapshot per `contracts/quick-setup-rest.md` §Route 1: servers via `MCPServerQuery::instance()->query([ 'orderby' => 'id', 'order' => 'ASC' ])`; abilities `total = count(wp_get_abilities())`; `plugins.acrossaiPro` + `plugins.abilitiesManager` via `is_plugin_active()` (include `require_once ABSPATH . 'wp-admin/includes/plugin.php'` guard mirroring `AIConnectorsPromoTab::ensure_plugin_helpers_loaded()`); wizardState from `get_transient('acrossai_mcp_manager_quick_setup_state_' . get_current_user_id())` with default `[ 'current_step' => 1, 'server_id' => null, ... ]`. **ALSO include `state.methods = ConnectionMethodRegistry::instance()->get_all()`** — returns the three-category shape `{ npm: [...], clients: [...], ai_connectors: [...] }` used by Step 5 sub-panels. This is the single canonical read for the wizard's connection-method DTOs; mirrors the pattern EmbedsTab already uses (see `admin/Partials/ServerTabs/EmbedsTab::get_state_for_server()` calling `ConnectionMethodRegistry::instance()->get_clients()` via `ClientEmbedTransport::get_dtos()`). Do NOT wrap in `AbstractEmbedTransport` — the wizard doesn't need per-server enabled state, only the raw DTO list.
- [x] T021 [US1] **Inherits TASK-SEC-003 error-hygiene constraint from T019.** Inside `QuickSetupController::handle_step()` — dispatch on `step` value; for step 1b (`data.new_server` present), call the shared sanitizer helper from T012 then `MCPServerQuery::instance()->add_item()`; for step 4, `MCPServerQuery::instance()->update_item($server_id, ['is_enabled' => (int) $data['enabled']])`. Always update the per-user transient scratchpad with 30-min TTL. Return updated wizardState + optionally refreshed servers[] per contract. Wrap any exception path in a hand-authored `WP_Error` — e.g., `new WP_Error('acrossai_mcp_quick_setup_persist_failed', esc_html__('Failed to persist step data. Try again.', 'acrossai-mcp-manager'), ['status' => 500])`. NEVER pass `$e->getMessage()` or `$wpdb->last_error` into the message field.
- [x] T022 [US1] **TASK-SEC-002** — Inside `QuickSetupController::handle_step()` step 3 branch: DO NOT call the F017 abilities REST route internally. Identify the F017 abilities-update SERVICE class method (search `includes/Abilities/` and `includes/REST/AbilitiesController.php` for the underlying persist method). If no public service method exists, extract one into a public method on the F017 abilities controller class. Wizard controller invokes the service directly with `server_id` + full ability set. Any exception is caught and wrapped into `WP_Error('acrossai_mcp_quick_setup_persist_failed', esc_html__('Failed to enable abilities. Try again.', 'acrossai-mcp-manager'), ['status' => 500])`. Reference: `security-constraints.md` SEC-002 + memory `DEC-ABILITY-OVERRIDE-RESOLUTION`.
- [x] T023 [US1] **Inherits TASK-SEC-003 error-hygiene constraint from T019.** Inside `QuickSetupController::handle_complete()` — `delete_transient('acrossai_mcp_manager_quick_setup_state_' . get_current_user_id())` and return `new WP_REST_Response(null, 204)`. Idempotent (double-call OK). No error branches expected under normal operation; any unexpected exception surfaces as a generic hand-authored `WP_Error` per T019 constraint — never raw exception text.
- [x] T024 [US1] Wire all new PHP surfaces in `includes/Main.php` — `define_admin_hooks()` gets: `ActivationRedirect::instance()` on `admin_init` @ 5. `define_public_hooks()` gets: `QuickSetupController::instance()` on `rest_api_init`. Use the plugin's canonical named-variable-then-Loader pattern per Constitution §Boot Flow — NEVER inline `Foo::instance()` as `add_action` arg. `AdminBarEntry` wiring lands in Phase 4 (US2).

### Frontend (React)

- [x] T025 [US1] React entry `src/js/quick-setup.js` — imports `../scss/quick-setup.scss`, mounts `<WizardStateProvider><App /></WizardStateProvider>` at `#acrossai-mcp-quick-setup-root` using `@wordpress/element` `createRoot`. Read bootstrap payload from `window.acrossaiMcpQuickSetup` for `restUrl`, `restNonce`, `adminUrl`, `logoUrl`.
- [x] T026 [US1] **TASK-SEC-005** — Inside `src/js/quick-setup.js` before mount, wire `@wordpress/api-fetch` middleware: `apiFetch.use( apiFetch.createNonceMiddleware( bootstrap.restNonce ) )`. **Do NOT wire `createRootURLMiddleware`** — WP core sets `wpApiSettings.root` for us and adding it risks the double-slash 404 (B25). Reference impl: F017 `abilities.js:95`. Also register a global `apiFetch` error handler: 403 responses surface a user-friendly "Your session has expired. Please reload the page to continue." error in the wizard's error store, not a generic network error. Reference: `security-constraints.md` SEC-005.
- [x] T027 [US1] Implement `App.jsx` in `src/js/quick-setup/App.jsx` — top-level component. On mount, calls `useWizardState().refetch()` to hydrate. Reads `step`/`method` from `useWizardRouter()`. Auto-skip logic (per US4): if `wizardState.enabled === true && step === '4'`, immediately calls `useWizardRouter().goTo('5')` (both forward from 3→5 and backward from 5→3 must skip 4). Renders `<StepLayout>{stepComponent}</StepLayout>` where `stepComponent` is the mapped step from a step-registry object. Wizard guards from step components consumed via `WizardGuardContext` (see T028).
- [x] T028 [US1] Implement `StepLayout.jsx` in `src/js/quick-setup/StepLayout.jsx` — header (logo `<img src={bootstrap.logoUrl} alt="AcrossAI"/>` + "Quick Setup" title + "Exit setup" link right-aligned → calls `useWizardRouter().exit()`). Progress bar `role="progressbar"` `aria-valuenow` `aria-valuemin={1}` `aria-valuemax={totalSteps}` (totalSteps = 4 when Step 4 auto-skipped, else 5). Content pane max-width per design. Footer with Back + Continue buttons; Back disabled on step 1; Continue label swaps to "Finish" on step 5, hidden on 'done'. Continue button uses `aria-disabled` (never `disabled` attribute alone) per FR-010a.
- [x] T029 [US1] StepLayout side-effect: on every step change, focus the first focusable element in the incoming step (via `useEffect` + step-content-ref `.focus()`) AND announce `"Step {N} of {total}, {step-title}"` into an `aria-live="polite"` region per FR-010a + `contracts/react-router.md` § Accessibility contract.
- [x] T030 [P] [US1] Implement `Step1_ServerPick.jsx` in `src/js/quick-setup/steps/Step1_ServerPick.jsx` — DataViews list OR `<RadioCard>` iteration over `state.servers`. Each row shows Name + `<code>` route (composed from `route_namespace/route`) + Inactive badge if `!enabled`. Below list: "+ Create a new server" link that flips local component state to render `<Step1_ServerCreate>` inline (not a modal). Advance guard via `useAdvanceGuard(wizardState.server_id !== null)`.
- [x] T031 [P] [US1] Implement `Step1_ServerCreate.jsx` in `src/js/quick-setup/steps/Step1_ServerCreate.jsx` — DataForm (from `@wordpress/dataviews`) with fields per `data-model.md` E3 + `contracts/quick-setup-rest.md` step 1b. Slug auto-derived from Name (JS `sanitize_title` shim — hyphen-lowercase-only); Route auto-derived from Slug. Buttons: "Create Server" primary → `saveStep(1, { new_server: {...} })`; "Cancel" → return to picker. On success, `wizardState.server_id` populates + picker re-renders with new row auto-selected.
- [x] T032 [P] [US1] Implement `Step2_AccessControl.jsx` in `src/js/quick-setup/steps/Step2_AccessControl.jsx` — extract shared component from `src/js/access-control.js` per `research.md` R7. **First refactor step**: change `src/js/access-control.js` to import + mount a new `<AccessControlEditor server_id={id} onSave={fn} />` component (extract to `src/js/access-control/AccessControlEditor.jsx`). Wizard's Step 2 imports the SAME component + mounts it inside the wizard's step pane. Above: `<Notice status="info">` with EXACT F042 admin-only banner text COPY-PASTED from `admin/Partials/ServerTabs/AccessControlTab.php` (do NOT paraphrase). Below: footnote "You can change this anytime under the server's Access Control tab." No advance guard.
- [x] T032-1 **REF-002 (TASK-SEC-T-002)** [US1] Immediately after T032's extraction lands: pre/post grep-based security-continuity check. **Pre-refactor** (capture BEFORE T032 edits): run `grep -nE "wp_create_nonce|['\"]nonce['\"]|dangerouslySetInnerHTML|current_user_can|apiFetch|wpApiSettings" src/js/access-control.js` and save the hit list. **Post-refactor** (verify AFTER T032 edits): run the same grep against `src/js/access-control/AccessControlEditor.jsx` — EVERY pre-refactor hit MUST have a corresponding call site in the extracted component (with the same argument shape). Any dropped hit is a rejected refactor — restore the security check before merge. Manual QA record: paste both grep outputs into the PR description. Reference: `security-constraints.md` SEC-T-002.
- [x] T033 [P] [US1] Implement `Step3_Abilities.jsx` in `src/js/quick-setup/steps/Step3_Abilities.jsx` — dual state per `spec.md` FR-015 (variant A: Abilities Manager inactive + promo card; variant B: active + real count + two buttons). Variant A promo card links: primary "Install from WordPress.org" → `https://wordpress.org/plugins/acrossai-abilities-manager/`; secondary "View case studies →" → `https://acrossai.co/use-cases/`. Variant B "Enable all abilities for this server" → `saveStep(3, { enable_all_abilities: true })`; "Configure abilities one-by-one" opens `?tab=abilities&server={id}` in NEW tab (`target="_blank"` + `rel="noopener"`). No advance guard.
- [x] T034 [P] [US1] Implement `Step4_EnableServer.jsx` in `src/js/quick-setup/steps/Step4_EnableServer.jsx` — yellow `<Notice status="warning">` per design + toggle switch (custom `<label>` with `<input type="checkbox">` visually hidden + custom-styled pill; matches design's toggle SVG shape). Toggle-on → `saveStep(4, { enabled: true })`. `useAdvanceGuard(wizardState.enabled === true)`. Auto-skip case is handled by App.jsx (T027) — this component only renders when the router lands on step 4 legitimately.
- [x] T035 [P] [US1] Implement `Step5_MethodGrid.jsx` in `src/js/quick-setup/steps/Step5_MethodGrid.jsx` — 2×2 grid of 4 `<RadioCard>`s: Connectors (branded, badge "PAID" or "PRO"), MCP Client, npm, WP-CLI. Connectors card tri-state per `spec.md` FR-020 driven by `state.plugins.acrossaiPro`: `missing` → "Get AcrossAI Pro →" link to `https://acrossai.co/pricing/#pricing` + trial trust line below grid ("Start on Personal with a 30-day free trial…" EXACT copy from user); `inactive` → yellow inline notice + "Activate AcrossAI Pro" button linking to `plugins.php` activate URL via `wp_nonce_url`; `active` → cards become radio-selectable; picking Connectors expands to `<Step5_ConnectorsPanel>` inline. Selecting any card → `saveStep(5, { method: 'connectors'|'client'|'npm'|'wpcli' })`. `useAdvanceGuard(wizardState.method !== null)`. Continue label = "Finish".
- [x] T036 [P] [US1] Implement `Step5_ConnectorsPanel.jsx` in `src/js/quick-setup/steps/Step5_ConnectorsPanel.jsx` — provider tabs read from `state.methods.ai_connectors` (populated by T020 from `ConnectionMethodRegistry::instance()->get_all()` — companion `acrossai-pro` plugin fills the list via the `acrossai_mcp_manager_discovery_ai_connectors` filter). Currently 4 built-in connectors (ChatGPT / Claude / Gemini / Grok) but tab count MUST be derived from `state.methods.ai_connectors.length`, not hardcoded — companion plugins that register additional connectors (e.g., Perplexity, Anthropic Web) auto-appear as extra tabs. This panel only renders when `state.plugins.acrossaiPro === 'active'` (guaranteed by T035's tri-state resolver); if `active` but the array is empty, render an empty-state notice. Each tab shows the plugin's canonical MCP URL for the selected server: `bootstrap.siteUrl + '/wp-json/' + server.route_namespace + '/' + server.route` — inside `<CodeBlock variant="inline">` + Copy button. Above the URL: description "This connector supports Dynamic Client Registration only — paste the MCP URL above into your AI client and it will register itself. No manual credentials to generate." Bootstrap payload MUST include `siteUrl` (add in T018 if not already).
- [x] T037 [P] [US1] Implement `Step5_ClientPanel.jsx` in `src/js/quick-setup/steps/Step5_ClientPanel.jsx` — pill row of the 8 clients read from `state.methods.clients` (populated by T020 from `ConnectionMethodRegistry::instance()->get_all()`). Each DTO shape: `{ category, slug, name, icon, description, ... }` — render `icon + name` in each pill. Currently 8 built-in clients (Claude Desktop, Claude Code, VS Code, GitHub Copilot, Cursor, Codex, Gemini CLI, Custom Client) but count MUST be derived from `state.methods.clients.length`, not hardcoded — companion plugins that register additional `AbstractMCPClient` subclasses via the `acrossai_mcp_client_classes` filter (per DEC-F034 pattern) auto-appear as extra pills. Selecting a pill reveals `<CodeBlock variant="pane">` with the JSON config for that client (populated with `server_slug` + site URL). Above the code block: `<Notice status="info">` with Application Password copy from `docs/quick-setup-design-brief.md`.
- [x] T038 [P] [US1] Implement `Step5_NpmPanel.jsx` in `src/js/quick-setup/steps/Step5_NpmPanel.jsx` — npm command(s) read from `state.methods.npm` (populated by T020 from `ConnectionMethodRegistry::instance()->get_all()`). Each DTO shape includes the `command` template + display metadata. Currently 1 built-in npm method (`npx -y @acrossai/mcp-manager --siteurl={site_url} --server={server_slug}`) but MUST iterate over `state.methods.npm` so future features that register additional npm methods auto-appear as extra code blocks. Render each as `<CodeBlock variant="inline">` with the template parameterized from `wizardState.server_id` → `state.servers` lookup. Two metadata rows (Site URL + Server) with `<code>` chips. Muted helper text about OS keychain storage per design brief.
- [x] T039 [P] [US1] Implement `Step5_WpCliPanel.jsx` in `src/js/quick-setup/steps/Step5_WpCliPanel.jsx` — three `<CodeBlock variant="inline">` blocks: `wp mcp-adapter list`, `wp mcp-adapter serve --server={server_slug} --user=admin`, plus the design's comparison note about STDIO vs HTTP transport.
- [x] T040 [US1] Implement `Completion.jsx` in `src/js/quick-setup/steps/Completion.jsx` — green checkmark circle + summary card with 4 rows (Server, Access, Abilities count, Connected via). Three CTAs: "Go to server dashboard" primary → `${bootstrap.adminUrl}&action=edit&server=${wizardState.server_id}`; "Set up another server" secondary → `complete()` then `useWizardRouter().goTo('1')`; "Dismiss" text link → `useWizardRouter().exit()`. Footer: "You can re-run this wizard anytime from the top admin bar." Continue button hidden per FR-010.

### Tests (US1)

- [x] T041 [P] [US1] `tests/phpunit/REST/QuickSetupControllerTest.php` — 15 test methods per `contracts/quick-setup-rest.md` § Test coverage: GET /state × 4 branches (subscriber 401, admin empty-scratchpad, admin populated, admin varied plugin states); POST /step × 9 branches (subscriber 401, bad nonce 403, invalid step 400, missing data 400, each of 5 valid step shapes 200, server_id gone 410, persist failure 500); POST /complete × 3 branches (subscriber 401, bad nonce 403, happy 204 idempotent).
- [x] T042 [P] [US1] `tests/phpunit/Admin/QuickSetup/ActivationRedirectTest.php` — test all 4 guard branches (transient absent no-op; transient present + non-admin skip; transient present + bulk-activation skip; transient present + network-activation skip); test happy-path redirect fires exactly once per transient set. Use the WP-tests `wp_redirect` filter throw pattern per B13.
- [x] T043 [P] [US1] `tests/phpunit/Admin/QuickSetup/QuickSetupPageTest.php` — test render hijack: `?page=acrossai_mcp_manager&quick-setup=1` renders the React mount div; missing `quick-setup=1` renders list-table normally; non-admin gets `wp_die`.

**Checkpoint (US1 = MVP)**: An admin activating the plugin lands on the wizard, completes all 5 steps, reaches the completion screen. This is the first shippable increment. Remaining user stories add polish + resilience on top of this base.

---

## Phase 4: User Story 2 — Returning admin re-runs setup from admin bar (Priority: P2)

**Goal**: A `manage_options` admin has a persistent entry point (top admin bar chip) that opens the wizard at step 1 from any admin screen.

**Independent Test**: From any admin page (not the plugin page) as `manage_options`, click the "Quick Setup for MCP" chip in the top admin bar → wizard opens at step 1. As an Editor, chip is absent.

- [x] T044 [US2] Implement `admin/Partials/QuickSetup/AdminBarEntry.php` — singleton; single public method `register_node(\WP_Admin_Bar $wp_admin_bar): void` per `plan.md` TASK-1 snippet. Cap check `current_user_can('manage_options')` first; then `$wp_admin_bar->add_node(['id' => 'acrossai-mcp-quick-setup', 'title' => '<span class="ab-icon dashicons dashicons-admin-tools" style="top:3px;"></span>' . esc_html__('Quick Setup for MCP', 'acrossai-mcp-manager'), 'href' => admin_url('admin.php?page=acrossai_mcp_manager&quick-setup=1&step=1'), 'meta' => ['title' => __('Guided 5-step MCP configuration', 'acrossai-mcp-manager')]])`.
- [x] T045 [US2] Wire `AdminBarEntry` in `includes/Main.php::define_admin_hooks()`: `$admin_bar = AdminBarEntry::instance(); $this->loader->add_action('admin_bar_menu', $admin_bar, 'register_node', 100);` per Constitution §Boot Flow named-variable pattern.
- [x] T046 [P] [US2] `tests/phpunit/Admin/QuickSetup/AdminBarEntryTest.php` — test node presence for admin (`current_user_can('manage_options')` mocked true) + absence for non-admin (Editor role); assert node id, title text, href match spec.

**Checkpoint (US2)**: Wizard reachable from anywhere in wp-admin as long as user is `manage_options`.

---

## Phase 5: User Story 3 — Admin resumes an in-flight wizard after browser reload (Priority: P2)

**Goal**: Reloading the browser mid-wizard restores the exact step + prior answers within the 30-min scratchpad TTL.

**Independent Test**: Advance to step 3 with prior answers → Cmd/Ctrl+R → wizard renders step 3 with prior selections. Wait > 30 minutes → wizard restarts at step 1.

**Rationale for tiny phase**: Persistence is already implemented in Phase 3 (scratchpad + REST). US3 adds only explicit hardening + one integration test.

- [x] T047 [US3] Verify `useWizardState.js` (T011) hydrates from `GET /state` on mount + writes on every step advance. Add a defensive re-fetch on `focus` event (browser tab regains focus) so scratchpad state from another tab syncs. Idempotent by design — no server-state changes on re-fetch.
- [ ] T048 [P] [US3] `tests/phpunit/REST/QuickSetupControllerTest.php` (extend the file from T041) — add a scenario: POST step 1 with server_id, POST step 2 with access_saved, then GET state → assert wizardState reflects both writes. Also assert TTL refresh: sleep 29 minutes (mocked), POST step 3, sleep 29 more minutes, GET state → data still present (server-side TTL refresh on every write per FR-026).

**Checkpoint (US3)**: Reload/tab-restore/focus-refocus all restore position.

---

## Phase 6: User Story 4 — Admin returning to an already-enabled server skips a step (Priority: P3)

**Goal**: When the selected server has `is_enabled = true`, step 4 is skipped entirely — both forward from step 3 and backward from step 5.

**Independent Test**: Enable a server via the list-table row action, then start wizard → step 4 never renders; Back button from step 5 lands on step 3; progress bar shows 4 total steps.

**Rationale for tiny phase**: Auto-skip logic sits in `App.jsx` (T027) already. US4 adds test hardening + progress-bar math confirmation.

- [x] T049 [US4] Confirm `App.jsx` auto-skip logic in T027 handles BOTH directions: `useEffect` on `[step, wizardState.enabled]` — if `step === '4' && wizardState.enabled`, `goTo('5')` (matches whether user came from step 3 forward or step 5 backward). Also confirm `StepLayout` progress bar's `totalSteps` calculation short-circuits to 4 when `wizardState.enabled === true` on entry.
- [ ] T050 [P] [US4] Manual QA per `quickstart.md` § "US4 auto-skip step 4" — enable a server, walk the wizard, observe progress bar (4 total), observe Back-from-5 lands on 3. Document result in the release-QA log.

**Checkpoint (US4)**: Already-enabled servers get a shorter wizard, correctly.

---

## Phase 7: User Story 5 — Admin deep-links a colleague into a specific step (Priority: P3)

**Goal**: `?step=5&method=client` (or any deep-link URL) works when preconditions are met; silently redirects to the furthest legitimate step when they aren't.

**Independent Test**: Open `?page=acrossai_mcp_manager&quick-setup=1&step=5&method=client` in a fresh session (no scratchpad, no server picked) → wizard silently redirects to `?step=1`. After completing step 1, reload the original deep-link URL → wizard lands on step 5 with the MCP Client panel expanded.

- [x] T051 [US5] Implement deep-link precondition check in `App.jsx` (T027) — on mount + on router-state change, evaluate the target step's precondition against `wizardState`: step 2+ requires `server_id`; step 5 no additional requirement beyond step-2's; step 'done' requires `method` set. If unmet, call `useWizardRouter().goTo(furthestLegitimateStep)` silently (no user-visible error, per FR User Story 5 Acceptance Scenario 2).
- [ ] T052 [P] [US5] Manual QA per `quickstart.md` § "US5 deep link" — verify fresh-session redirect + post-hydration deep-link honor.

**Checkpoint (US5)**: Agency handoff URLs work; broken deep-links fall back gracefully.

---

## Phase 8: Polish & Cross-Cutting Concerns

**Purpose**: Version bump + changelog + memory hygiene + security grep gates + additive-only grep gate + Constitution §VII DoD checklist.

- [ ] T053 Bump version 0.2.10 → 0.2.11 in three files: `acrossai-mcp-manager.php` header `Version: 0.2.11`, `includes/Main.php` constant `ACROSSAI_MCP_MANAGER_VERSION` = `'0.2.11'`, `README.txt` `Stable tag: 0.2.11`.
- [ ] T054 Add `= 0.2.11 =` changelog section to `README.txt` per the outline in `docs/planings-tasks/069-mcp-quick-setup-wizard.md` § TASK-12. Verify total changelog word count stays under WordPress.org's 5,000-word cap.
- [ ] T055 [P] **TASK-SEC-003** — Grep gate: `grep -rEn "'message'\s*=>\s*\\\$e->getMessage\(\)|'message'\s*=>\s*\\\$wpdb->" includes/REST/QuickSetupController.php` MUST return zero matches. Every `WP_Error` returned by the wizard REST layer uses a hand-authored, user-facing message. Reference: `security-constraints.md` SEC-003.
- [ ] T056 [P] **TASK-SEC-004** — Grep gate: `grep -rn "dangerouslySetInnerHTML" src/js/quick-setup/` MUST return zero matches. Reference: `security-constraints.md` SEC-004.
- [ ] T057 [P] Additive-only invariant grep gate per `spec.md` § "Final full-repo audit": `git diff main..HEAD --stat -- admin/Partials/ServerTabs/ includes/REST/ includes/Database/` MUST show zero non-additive deletions (except intentional docblock edits explicitly called out; the AC-editor extraction in T032 is the ONE exception — `src/js/access-control.js` refactor is additive-shaped-as-extraction).
- [ ] T058 [P] Bundle-gating invariant grep: `grep -rEn 'acrossai-mcp-manager-quick-setup' admin/ includes/` — every hit MUST sit inside a `! empty( $_GET['quick-setup'] )` conditional. Confirms SC-007 (bundle never loads outside wizard URL).
- [ ] T059 Run `npm run build` — verify `build/js/quick-setup.{js,css,asset.php}` all present + bundle sizes ≤ 250KB JS + 40KB CSS (Technical Context targets).
- [ ] T060 Constitution §VII DoD gate — run `composer run phpcs` (zero errors, zero warnings), `composer run phpstan` (zero errors at level 8), `npm run lint:js` (zero errors), `vendor/bin/phpunit --testsuite admin` (all new admin/QuickSetup tests + REST QuickSetupController test pass), `npm run validate-packages` (Tier-1 @wordpress/* only).
- [ ] T061 Manual smoke test per `quickstart.md` — walk all 5 user story paths (US1 fresh install, US2 admin bar chip, US3 reload, US4 auto-skip, US5 deep link) + a11y test (SC-010 keyboard-only walkthrough + axe DevTools scan on every step, zero WCAG 2.1 AA violations). **Additional QA items added post-tasks-review**:
  - **TASK-SEC-T-004 (403 error-message assertion)**: temporarily lower REST nonce lifetime to 60 seconds via `add_filter('nonce_life', fn() => 60)` in a mu-plugin, load the wizard, wait 65 seconds, attempt Continue on any step, verify the error surface shows exactly `"Your session has expired. Please reload the page to continue."` (not a generic "network error"). Remove the mu-plugin filter after the test.
  - **REF-002 continuity grep pair** (from T032-1): capture pre/post-refactor grep output for AC editor extraction. Both grep results MUST be pasted into the PR description as the audit trail. Verify every pre-refactor security-check hit (nonce, cap, dangerouslySetInnerHTML, apiFetch) has a corresponding call site post-refactor.
- [ ] T062 Memory hygiene per `plan.md` § Governance Summary Recommended Action #3 — document TWO future capture-from-diff candidates in `docs/memory/WORKLOG.md` under a Feature 069 milestone entry: (a) "AC editor extraction pattern — reusable when a 2nd surface needs an existing tab's React functionality" (proven by T032); (b) "Cross-feature service-class routing over internal REST — formalize as DEC if TASK-SEC-002 lands cleanly" (proven by T022 if it doesn't regress via review pushback). No formal capture during this feature — deferred to post-implementation `/speckit-memory-md-capture-from-diff` at merge.
- [ ] T063 Update `docs/planings-tasks/README.md` to index `docs/planings-tasks/069-mcp-quick-setup-wizard.md` (already committed as part of the planning-phase checkpoint).

**Checkpoint (Polish)**: Every DoD gate passes; ready to ship on branch `069-mcp-quick-setup-wizard` via PR.

---

## Dependencies

### Story completion order

- **Phase 1 (Setup)** MUST complete before Phase 2.
- **Phase 2 (Foundational)** MUST complete before any user story phase (3-7).
- **Phase 3 (US1 = MVP)** MUST complete before Phase 4/5/6/7 — every subsequent user story depends on the wizard actually rendering and the REST controller actually persisting.
- **Phase 4 (US2)**, **Phase 5 (US3)**, **Phase 6 (US4)**, **Phase 7 (US5)** are all independent of each other post-US1 — can be worked in any order or in parallel by a team.
- **Phase 8 (Polish)** MUST complete last (gates on all prior phases).

### Task-level dependencies (within Phase 3 = US1)

- T014 (activation transient set) → prerequisite for T015 (redirect handler) but neither is prerequisite for T016+ (page render).
- T016 (page render) + T018 (asset enqueue) MUST both land for the React app to boot.
- T019 (REST route registration) → prerequisite for T020/T021/T022/T023 (handlers) — write skeleton first, fill handlers second, but can be in the same commit.
- T025 (React entry) + T027 (App.jsx) + T028 (StepLayout) MUST land before ANY step component can render.
- T030-T040 (individual step components) can proceed in parallel once T027 + T028 are in place.
- T012 (shared sanitizer, in Phase 2) MUST land before T021's step-1b handler can call it.
- T032 (AccessControlEditor extraction) MUST land before T024's Main.php wiring change touches `access-control.js`.

## Parallel Execution Examples

**Phase 2 (foundational)** — six [P]-tagged React components can be authored in parallel (T004 SCSS, T005 asset copy, T006 icons, T007 Notice, T008 CodeBlock, T009 RadioCard). PHP sanitizer T012 is independent of any React work.

**Phase 3 (US1) — step components** — T030-T040 all [P]-tagged, all in different files, all depend only on Phase 2 components + T027/T028 shell. A team of 5 could parallelize:
- Dev A: T030 (Step 1 pick) + T031 (Step 1 create)
- Dev B: T032 (Step 2 AC extraction) — depends on shared component extraction
- Dev C: T033 (Step 3 abilities) + T040 (Completion)
- Dev D: T034 (Step 4 enable) + T035 (Step 5 grid)
- Dev E: T036/T037/T038/T039 (Step 5 sub-panels)

**Phase 3 (US1) — tests** — T041/T042/T043 all [P]-tagged, three different test files, can be authored in parallel with implementation once REST + admin surfaces exist.

**Phase 8 (Polish)** — T055/T056/T057/T058 are four independent grep gates, all [P]-tagged, can run in parallel.

## Implementation Strategy

**MVP-first**: Complete Phase 1 + Phase 2 + Phase 3 (US1 only) → SHIPPABLE. This delivers the first-run redirect + full wizard flow that solves the core "reduce setup friction" problem. US2-US5 are pure additions.

**Increment after MVP**: US2 (admin bar chip) is the highest-value follow-on (persistent entry point). US3 (reload resume) has near-zero incremental effort. US4 + US5 are optimizations for niche flows.

**Fold ordering suggestion for a single-PR merge**: MVP + all user stories in one PR since the feature is atomic (no partial shipping value — half a wizard confuses users more than no wizard). BUT: task-level review can proceed in the phase order above, so early US1 tasks can be reviewed while later step components are still in development.

---

## Security-review cross-reference

| Finding | Severity | Folded as | Landing phase |
|---|---|---|---|
| SEC-001 (sanitizer drift) | Medium | TASK-SEC-001 = T012 | Phase 2 (foundational, shared helper) |
| SEC-002 (internal REST-to-REST) | Medium | TASK-SEC-002 = T022 | Phase 3 US1 (Step 3 handler) |
| SEC-003 (error message hygiene) | Low | TASK-SEC-003 = T055 | Phase 8 (grep gate) |
| SEC-004 (dangerouslySetInnerHTML forbidden) | Low | TASK-SEC-004 = T056 | Phase 8 (grep gate) |
| SEC-005 (nonce middleware wiring) | Low | TASK-SEC-005 = T026 | Phase 3 US1 (React entry) |

---

## Format validation

Every task above follows `- [ ] TXXX [P?] [USN?] Description with file path`:
- All 63 tasks start with `- [ ]` ✅
- All 63 tasks have sequential IDs T001-T063 ✅
- [P] marker only on parallelizable tasks (different files, no dependencies on incomplete tasks) ✅
- [USN] label only on Phase 3-7 tasks; Phase 1 (Setup), Phase 2 (Foundational), Phase 8 (Polish) tasks unlabeled ✅
- Every task cites a concrete file path or shell command target ✅

Total: **63 tasks** across 8 phases. MVP = Tasks T001–T043 (Phase 1 + 2 + 3). Independent story tests present for every user story (US1–US5).

---

## Phase 9: Post-MVP polish + 13-step dynamic flow (delivered 2026-08-19)

**Purpose**: Reshape the shipped MVP into a fully-branching wizard driven by 10 skip predicates. Not a "user story" in the original sense — this phase is a design evolution from user feedback after MVP acceptance. All tasks land on branch `069-quick-setup-13-step-flow` off `069-mcp-quick-setup-wizard`.

**Scope**: 6 new steps (4, 8, 9, 10, 11, 12, 13), dynamic skip logic, gate-card visual grammar, full-screen loading overlay, WP `<Spinner />` adoption, URL server_id mirror, backend fresh-abilities on every /step response.

**Notes on original tasks**: Phase 3-8 tasks (T014–T063) remain the canonical acceptance criteria for the 5-step MVP. Phase 9 does not retroactively invalidate them — each was a valid acceptance gate at MVP ship. The renaming that landed in Phase 9 (Step5_MethodGrid → Step7_MethodGrid, etc.) preserved git history via `git mv` where possible; content-heavy rewrites show as delete+add pairs (see git log --follow).

### Backend (PHP)

- [x] T064 **`QuickSetupController::VALID_STEPS`** bumped from `[1..5]` to `[1..13]`. New step handlers 4/8/9/10/11/12/13 are scratchpad-only acks (record `current_step`, no persistent state).
- [x] T065 New route `POST /quick-setup/install-plugin` with slug whitelist (`acrossai-abilities-manager`, `acrossai-pro`). Uses WP core `Plugin_Upgrader` to download from WP.org + activate. Idempotent — skips install if plugin is already installed.
- [x] T066 New `install_plugin_permission_check()` — requires BOTH `install_plugins` AND `activate_plugins` capabilities.
- [x] T067 `collect_abilities_summary()` extended to accept `?int $server_id` and compute `enabledForServer` via `MCPServerAbilityExposureResolver::resolve()`. Returned in `abilities.enabledForServer` (int when known, null when no server chosen).
- [x] T068 `handle_step()` piggybacks fresh `abilities` payload onto every /step response when scratchpad has server_id — eliminates need for follow-up /state refetch to keep the Step 5 `X/Y enabled` count fresh.
- [x] T069 `write_scratchpad()` tolerates `set_transient()`'s "no change → false" return by reading the transient back and comparing. Fixes the spurious `acrossai_mcp_quick_setup_persist_failed` error when re-clicking a step's primary action with identical payload.
- [x] T070 `default_scratchpad()` gains `create_intent` flag for step 1's "Create new server" branch.
- [x] T071 `collect_plugin_states()` returns `trialEndDate` = `wp_date('F j, Y', strtotime('+30 days'))` for Step 7 promo bar + Step 8 pitch.
- [x] T072 `handle_state()` now reads scratchpad `server_id` first so `collect_abilities_summary($server_id)` runs with authoritative context on cold hydrate.

### Frontend routing

- [x] T073 `useWizardRouter.STEP_ORDER` grew to 13. `shouldSkip()` handles 10 skip predicates: `skipCreate`, `skipAbilitiesGate`, `skipAbilities`, `skipEnable`, `skipProPromo`, `skipProActivate`, `skipConnectorsDetail`, `skipClient`, `skipNpm`, `skipWpcli`. `advance`/`back` walk past chained skips in one call.
- [x] T074 Router API extended: `readParams()` parses `?server=<id>`, `buildUrl()` writes/strips it, new `setServer(id)` uses `replaceState` (not `pushState`) so mirroring server_id doesn't pollute browser history.

### Frontend orchestration

- [x] T075 `App.jsx` step registry covers 1-13 + done. Auto-skip effect covers 2/4/5/6/8/9/10/11/12/13. New `stepVisibilityTable` centralizes step ↔ skip mapping; `totalSteps` and `displayIndex` derive from it.
- [x] T076 `App.jsx` computes `selectedServer` from `state.servers` (DB truth) — `skipEnable` reads `selectedServer.enabled` NOT the scratchpad flag. Correctly recognizes servers enabled outside the wizard.
- [x] T077 `App.jsx` `hasHydratedOnceRef` — initial-loading gate now only fires on `state.status === 'idle'` OR first-ever `'loading'`. Subsequent refetches (visibilitychange / focus / popstate) leave the wizard mounted — StepLayout's `busy` overlay handles the loading UI. Prevents step unmount + local state loss on every tab return.
- [x] T078 `App.jsx` `isTerminalStep = router.step ∈ {10,11,12,13}` → Finish → done.
- [x] T079 `App.jsx` server_id sync effect: mirrors `state.wizardState.server_id` into URL as `?server=<id>` for shareable deep links.

### Frontend hooks

- [x] T080 `useAdvanceGuard` context extended with `setFooterAction`, `setHideContinue`, `advance` — plus new exported hooks `useFooterAction(action)`, `useHideContinue(hide)`, `useWizardAdvance()`.
- [x] T081 `useWizardState` reducer for `SAVE_STEP_SUCCESS` merges `abilities` from payload (so T068 backend piggyback lands).
- [x] T082 `useWizardState` reducer for `COMPLETE_SUCCESS` no longer resets `wizardState` — the Completion screen needs it to render the summary + wire the "Go to server dashboard" button. Reset happens naturally via refetch when user clicks "Set up another server".
- [x] T083 `useWizardState` adds `visibilitychange` + `popstate` refetch listeners alongside the existing `focus` fallback. Catches state changes made in other tabs (e.g. server disabled from the server list).

### Steps

- [x] T084 Step 1: `Step1_ServerPick` — inline `?mode=create` removed; "+ Create a new server" is now a selectable card that sets `create_intent`. On first mount with no prior pick, auto-selects the seeded default server (slug `mcp-adapter-default-server`, kept in sync with `DefaultServerSeeder::SLUG`).
- [x] T085 Step 2: `Step2_ServerCreate` (renamed from `Step1_ServerCreate` via git mv) — dedicated create-form step. Uses `useAdvanceGuard(canAdvance, beforeAdvance)` to submit-on-Continue.
- [x] T086 Step 3: `Step3_AccessControl` — footer becomes Back · Continue · Save and Continue. Vendor "Save Access Control" button hidden via `hideSaveButton` prop; wizard fires the same PUT/DELETE from a footer action. Uses `useWizardAdvance()`.
- [x] T087 Step 4 (NEW): `Step4_AbilitiesManager` — install/activate gate for `acrossai-abilities-manager`. Missing → "Install & Continue" (POST /install-plugin), Inactive → "Activate & Continue" (same endpoint). Continue always allowed (user can skip; Step 5 falls back to "WP core abilities only" variant).
- [x] T088 Step 5: `Step5_Abilities` (renamed from `Step3_Abilities`) — headline flips from `total` to `X/Y enabled for this server`. Auto-skipped when `enabledForServer >= total`. Footer registers "Enable all and continue" via `useFooterAction`; auto-skip handles the post-enable jump (no manual `router.advance()` — avoids the double-jump race).
- [x] T089 Step 6: `Step6_EnableServer` (renamed from `Step4_EnableServer`) — checkbox removed. Uses `useHideContinue(true)` so ONLY "Enable & Continue" advances (Back still works). Warning-variant gate card (`.qs__gate-card--warning`) + `createInterpolateElement`-composed info notice with link to `WordPress/mcp-adapter`.
- [x] T090 Step 7: `Step7_MethodGrid` (renamed from `Step5_MethodGrid`) — inline expansion removed; clicking a card saves + selects (does NOT auto-advance). Continue uses App's `handleContinue` with fresh skips. PAID badge + trial promo bar rendered when `state.plugins.acrossaiPro !== 'active'`.
- [x] T091 Step 8 (NEW): `Step8_ProPromo` — full-page trial pitch with 6 value bullets + "Start free trial" CTA to `https://acrossai.co/pricing/#pricing`. Continue disabled; auto-skip fires when Pro flips to inactive/active.
- [x] T092 Step 9 (NEW): `Step9_ProActivate` — activation gate. "Go to Add-ons page" link to `admin.php?page=acrossai-addons` (new bootstrap `addonsUrl`) + "I've activated it — re-check" refetch button. Continue disabled.
- [x] T093 Step 10-13 (renamed from Step5_ConnectorsPanel/ClientPanel/NpmPanel/WpCliPanel via git mv): terminal method-specific detail screens. Only ONE renders per run based on picked method.
- [x] T094 `Completion.jsx`: Abilities summary row uses authoritative `state.abilities.enabledForServer` instead of the scratchpad's `abilities_saved` flag.

### Chrome + shared UI

- [x] T095 `StepLayout` — `STEP_TITLES` extended to 1-13. `isLast` matches `{10,11,12,13}` → "Finish". Step counter text removed (progress bar + ARIA live region communicate position without redundant text).
- [x] T096 `StepLayout` — full-screen loading overlay owned here, driven by `busy = isLoading || footerAction?.isLoading`. Reuses `.qs__initial-loading` element + `--overlay` modifier (translucent backdrop, z-index above WP admin bar, fade-in). All three footer buttons locked during `busy`.
- [x] T097 `StepLayout` — supports optional `footerAction` (third primary button after Continue) and `hideContinue` (Step 6 opt-out).
- [x] T098 `qs__gate-card` reusable class (Step 4/8) + `--warning` modifier (Step 6). `qs__gate-stat` / `qs__gate-stat-icon` / `qs__gate-bullets` / `qs__gate-copy` sub-classes.
- [x] T099 `qs__trial-bar` reusable class (Step 7 promo) + trial CTA copy pulls `trialEndDate` from state.
- [x] T100 `qs__initial-loading` — full-viewport centered brand icon (`assets/quick-setup/icon.svg` = copy of `.wordpress-org/icon.svg` since dotfile dirs are commonly blocked at the host level). Soft pulse animation (opacity + scale, 1.6s cycle).
- [x] T101 `.qs-notice` — `display: flex` → `display: block` fix so `createInterpolateElement` mixed inline content (text + `<a>` + text) flows as normal typographic text instead of fracturing into three flex columns.

### Bootstrap + docs

- [x] T102 `admin/Main.php` bootstrap payload gains `iconUrl` (initial-loading icon) and `addonsUrl` (Step 9 Pro activation destination).

### Test coverage

- [x] T103 `QuickSetupControllerTest`: bumped step numbers (1→2 for create, 5→7 for method), added `@dataProvider provide_no_op_terminal_steps` for 8-13 handler smoke, added `install_plugin` permission + slug-whitelist tests, added `test_post_step_succeeds_when_scratchpad_payload_unchanged` regression for T069, added `test_get_state_includes_trial_end_date` shape check.

**Checkpoint (Phase 9)**: Wizard renders 5-10 visible steps per run depending on state; all 10 skip predicates + auto-skip effect verified via manual walkthrough; PHPCS clean; JS build clean; no regressions in MVP acceptance flow.

Total after Phase 9: **63 MVP + 40 polish = 103 tasks**.
