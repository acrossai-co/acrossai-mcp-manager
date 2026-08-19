# Feature Specification: MCP Quick Setup Wizard

**Feature Branch**: `069-mcp-quick-setup-wizard`
**Created**: 2026-08-16
**Status**: Draft
**Input**: User description: "Add a linear 5-step admin wizard that condenses the existing 11-tab per-server-edit page into a single guided flow. Entry points: (a) new admin-bar node 'MCP Quick Setup' with dashicon wrench gated on manage_options and rendered via admin_bar_menu at priority 100; (b) dismissible notice on the plugin page while a first-run transient is live. Both entry points target admin.php?page=acrossai_mcp_manager&quick-setup=1&step=1 …"

## Clarifications

### Session 2026-08-16

- Q: What accessibility posture does the wizard commit to? → A: WCAG 2.1 AA with explicit keyboard-nav, focus mgmt on step advance, and ARIA live-region progress announcements
- Q: How should users first discover the wizard? → A: Remove the first-run banner entirely. On plugin activation, redirect the activating admin directly to the wizard. Keep only the admin bar chip (labeled "Quick Setup for MCP") for later re-runs.
- Q: Which observability hooks does the wizard emit? → A: None. Wizard is intentionally silent; sibling F015/F042-style `do_action` hooks are not added in this feature and can be introduced later on demand.
- Q: What server-count scale must Step 1 support without UX degradation? → A: Small — 2-3 servers for 95% of installs, worst case 10-12. No pagination or search on the picker; matches the plugin's existing list-table default (per-page 20).

## User Scenarios & Testing *(mandatory)*

### User Story 1 — First-time admin completes end-to-end setup (Priority: P1)

A site administrator installs the plugin for the first time. The moment they click **Activate** on the WordPress plugins list, the browser lands them directly on **Step 1** of the wizard — no intermediate settings page, no banner to notice. They're walked through five short steps: pick a server (or create one), choose who can access it, decide which abilities to expose, enable the server, and pick how to connect an AI client. On the final screen they see a summary of every choice and a "Go to server dashboard" button. Total time on task: under 3 minutes.

**Why this priority**: This is the reason the feature exists. Every other capability (admin bar entry, deep links) serves this journey. If a first-run admin can complete it end-to-end without any friction between activation and the wizard, the feature is a success.

**Independent Test**: On a fresh install with neither Abilities Manager nor AcrossAI Pro present, click **Activate** on the plugins list, confirm the next page rendered is Step 1 of the wizard, walk all 5 steps accepting defaults, and confirm the completion screen shows a valid summary + a clickable "Go to server dashboard" link that opens the server-edit page.

**Acceptance Scenarios**:

1. **Given** the plugin was just activated by a `manage_options` user via the WordPress plugins list, **When** the next admin page loads, **Then** the browser is redirected to Step 1 of the wizard automatically.
2. **Given** the admin is on step 1 with the seeded server selected, **When** they click Continue, **Then** step 2 loads with the access-control editor scoped to the selected server and Continue is enabled.
3. **Given** the admin completes step 5 by picking any connection method, **When** they click Finish, **Then** the completion screen renders with the four summary rows populated (Server, Access, Abilities, Connected via).

---

### User Story 2 — Returning admin re-runs setup from admin bar (Priority: P2)

An admin who already completed initial setup wants to set up a second MCP server. They click the **MCP Quick Setup** chip in the top admin bar (visible on every admin screen) and go straight into the wizard. They advance through step 1 by clicking **+ Create a new server**, fill the inline form, and continue.

**Why this priority**: The admin bar entry is the wizard's persistent entry point. The activation redirect (User Story 1) is one-shot per activation, so without the admin bar chip, users could only reach the wizard immediately after Activate — never later. This story validates the always-available path.

**Independent Test**: From any admin screen (not the plugin page), click the top admin bar chip labeled "MCP Quick Setup" and confirm it navigates to the wizard's step 1 without any intermediate page.

**Acceptance Scenarios**:

1. **Given** a `manage_options` user is on any admin screen, **When** they open the admin bar, **Then** the "MCP Quick Setup" chip is visible with a wrench icon.
2. **Given** a non-admin user (e.g., Editor) is logged in, **When** they open the admin bar, **Then** the "MCP Quick Setup" chip is absent.
3. **Given** the admin clicks the chip, **When** the wizard loads, **Then** it opens at step 1 (server picker) regardless of previously saved state, so the user is not confused by mid-wizard resumption.

---

### User Story 3 — Admin resumes an in-flight wizard after browser reload (Priority: P2)

Mid-wizard, an admin reloads the browser (accidental refresh, tab restore, follow a link and go back). The wizard restores the exact step they were on and the answers they had entered so far.

**Why this priority**: Long forms lose users when interruptions cause data loss. Persistence via URL + short-lived server-side scratchpad turns a friction point into a non-event.

**Independent Test**: On step 3 with some answers entered on prior steps, reload the browser; the wizard MUST render step 3 with prior selections intact.

**Acceptance Scenarios**:

1. **Given** the admin is on step 3 with a server selected in step 1 and an access rule saved in step 2, **When** they reload the browser, **Then** step 3 renders with the same server + rule still selected (visible on step nav / summary).
2. **Given** the admin closes the browser tab entirely and reopens the URL within 30 minutes, **When** the wizard loads, **Then** their prior answers are restored.
3. **Given** more than 30 minutes have elapsed since the admin last saved a step, **When** they reopen the wizard URL, **Then** the wizard starts fresh at step 1 with no restored state.

---

### User Story 4 — Admin returning to an already-enabled server skips a step (Priority: P3)

An admin runs the wizard against a server that was previously enabled. The "Enable server" step is skipped entirely; the progress bar shows 4 steps instead of 5. Continue on step 3 goes directly to step 5.

**Why this priority**: Not repeating work the admin already did keeps the wizard feeling responsive and intelligent rather than dumbly linear.

**Independent Test**: Enable an MCP server outside the wizard, then start the wizard and pick that server on step 1. Step 4 MUST NOT appear in the flow.

**Acceptance Scenarios**:

1. **Given** the selected server is already enabled, **When** the admin advances past step 3, **Then** the wizard skips step 4 and lands on step 5.
2. **Given** step 4 is skipped, **When** the admin uses the browser Back button on step 5, **Then** they land on step 3 (not step 4).
3. **Given** step 4 is skipped, **When** the wizard renders, **Then** the visual progress indicator reflects 4 total steps (not 5).

---

### User Story 5 — Admin deep-links a colleague into a specific step (Priority: P3)

An agency contractor sends a client a URL that includes `&step=5&method=client` so the client lands directly on the MCP-Client configuration panel with the JSON copy block visible. The client copies the config and pastes it into Claude Desktop without ever seeing steps 1–4.

**Why this priority**: Supports agency handoff and support-team workflows. Not required for the core first-run journey but a meaningful multiplier when the wizard is used repeatedly.

**Independent Test**: Open `?page=acrossai_mcp_manager&quick-setup=1&step=5&method=client` directly in a new tab; the wizard MUST render step 5 with the MCP Client panel expanded.

**Acceptance Scenarios**:

1. **Given** a valid deep-link URL for step 5 with a method parameter, **When** an admin opens it, **Then** the wizard renders exactly that step + method view.
2. **Given** a deep-link URL that references a step whose prerequisites are unmet (e.g., no server selected), **When** the admin opens it, **Then** the wizard silently redirects to the furthest step the current state can legitimately support.
3. **Given** the admin uses browser Back on a deep-linked step, **When** the navigation completes, **Then** they land on the prior step in the URL history (not necessarily the previous wizard step).

---

### Edge Cases

- **Missing sibling plugin (Abilities Manager)**: step 3 renders a promotional variant explaining that WordPress ships with only 3 default abilities + a WordPress.org install link. Never blocks progress.
- **Missing paid add-on (AcrossAI Pro)**: step 5's Connectors card shows a "Get AcrossAI Pro" pricing link + 30-day trial trust line. Never blocks the other three connection methods.
- **Paid add-on installed but not active**: step 5's Connectors card shows a yellow "installed but not active" notice + an in-place activate button.
- **Server deleted mid-wizard by another admin**: the wizard MUST detect the missing server on the next `saveStep` call and surface an inline error with "Restart wizard" + "Pick different server" buttons.
- **User loses `manage_options` capability mid-wizard**: the next REST call returns 403; the wizard MUST replace the current step with a "Session ended — you no longer have permission" notice.
- **Browser blocks pop-state or `history.pushState` (e.g., very strict Safari privacy mode)**: URL fails to update but the wizard MUST still function as a linear form; Back button falls back to an in-memory step index.
- **JavaScript disabled**: `<noscript>` renders a message directing the admin to enable JS; no wizard UI is offered.
- **Concurrent activation of AcrossAI Pro in another tab while the wizard is open on step 5**: the wizard MUST NOT hot-swap state; the current-tab experience is stable until the admin advances to a new step or reloads.

---

## Requirements *(mandatory)*

### Functional Requirements

**Entry points**

- **FR-001**: The plugin MUST add a "Quick Setup for MCP" node to the WordPress top admin bar for users with `manage_options`, visible on every admin screen.
- **FR-002**: The admin bar node MUST link to the wizard's step 1 URL and open in the same tab (no new-tab navigation).
- **FR-003** (activation redirect): On plugin activation, the plugin MUST redirect the activating administrator's next admin page load to the wizard's step 1 URL. The redirect MUST fire exactly once per activation.
- **FR-004** (activation redirect — safety guards): The redirect MUST be suppressed when the activation is part of a bulk activation (multiple plugins activated in one WordPress action) or a network-wide activation on multisite. The redirect MUST also be suppressed if the activating user cannot `manage_options` (fail closed — better to not redirect than to redirect a user to a permission-denied page).
- **FR-005** (activation redirect — one-shot signal): The redirect MUST be driven by a short-lived server-side signal that is deleted the moment the redirect is served (or, if never consumed within 30 seconds of activation, auto-expires). No banner, notice, or persistent nag surface exists on the plugin's own settings page.

**Wizard shell**

- **FR-006**: The wizard MUST be reachable at a URL that hijacks the plugin's own settings page. When the wizard URL parameter is present, the plugin's server list-table MUST NOT render.
- **FR-007**: A user without `manage_options` who reaches the wizard URL MUST be blocked with a permission-denied message (not a redirect to the list-table).
- **FR-008**: The wizard's step index MUST live in the URL as a query parameter and MUST be the source of truth. Browser Back/Forward MUST navigate steps without a full page reload.
- **FR-009**: The wizard MUST render a linear progress indicator whose fill reflects the ratio of current step to total remaining steps (accounting for auto-skipped steps).
- **FR-010**: Each step MUST offer a Back button (disabled on step 1) and a Continue button (label changes to "Finish" on the final step and is hidden on the completion screen).
- **FR-010a** (accessibility): The wizard MUST meet WCAG 2.1 AA. Every step MUST be completable using the keyboard only (no mouse required). On each step advance, keyboard focus MUST move to the first focusable interactive element of the incoming step (not to `<body>`). Step-index changes MUST be announced to assistive technologies via an ARIA live region (e.g., "Step 2 of 5, Access Control"). All interactive controls MUST have accessible names; the progress indicator MUST expose `role="progressbar"` with `aria-valuenow` / `aria-valuemin` / `aria-valuemax`.

**Per-step behavior**

- **FR-011** (Step 1 — server picker): The step MUST list every existing MCP server with each row showing name, route, and an inactive badge if applicable. The admin MUST be able to select exactly one server. Target scale is ≤20 servers (95% of installs run 2-3; edge case 10-12); no pagination, no search filter — a plain vertical list is sufficient at this scale.
- **FR-012** (Step 1 — server create): The step MUST offer an inline "Create a new server" form. On successful create, the new server is auto-selected and the picker updates without a page reload.
- **FR-013** (Step 1 — advance guard): Continue MUST be disabled until a server is selected.
- **FR-014** (Step 2 — access control): The step MUST render the plugin's existing access-control editor scoped to the selected server. The step MUST show an info banner explaining the "administrators only by default" behavior and MUST allow advancement without any rule change.
- **FR-015** (Step 3 — abilities dual state): When the Abilities Manager companion plugin is inactive, the step MUST show a promotional card + install link. When active, the step MUST show the real ability count + "Enable all" and "Configure one-by-one" actions.
- **FR-016** (Step 3 — external tab link): The "Configure abilities one-by-one" action MUST open the existing Abilities tab in a new browser tab so the wizard is not lost.
- **FR-017** (Step 4 — auto-skip): When the selected server is already enabled, the wizard MUST skip step 4 entirely, in both forward navigation from step 3 and Back navigation from step 5.
- **FR-018** (Step 4 — enable): When shown, the step MUST offer a single toggle that enables the server. Continue MUST be disabled until the toggle is on.
- **FR-019** (Step 5 — method grid): The step MUST render four connection-method cards (Connectors, MCP Client, npm, WP-CLI). Selecting a card MUST reveal that method's setup content inline.
- **FR-020** (Step 5 — Connectors tri-state): The Connectors card MUST render one of three sub-states based on the AcrossAI Pro add-on: missing → pricing CTA + trial trust copy; inactive → activate button + notice; active → four provider tabs (ChatGPT / Claude / Gemini / Grok) each showing the server's MCP URL.
- **FR-021** (Step 5 — client picker): The MCP Client sub-view MUST render a pill row of every supported client from the plugin's existing client registry. Selecting a pill MUST reveal the corresponding JSON config with a copy-to-clipboard action.
- **FR-022** (Step 5 — npm / WP-CLI): Each sub-view MUST render the exact command string parameterized with the selected server's slug and the site URL, with a copy-to-clipboard action.
- **FR-023** (Step 5 — completion): Clicking Finish MUST mark the wizard complete and navigate to a completion screen.
- **FR-024** (Completion screen): The completion screen MUST summarize the four choices (server, access, abilities, connection method) and MUST offer three actions: "Go to server dashboard", "Set up another server", "Dismiss".

**Persistence + resumption**

- **FR-025**: Every step's answers MUST persist to a per-user server-side scratchpad within 500ms of the user leaving the step.
- **FR-026**: The scratchpad MUST auto-expire within 30 minutes of the last write and MUST be isolated per user.
- **FR-027**: On completion the scratchpad MUST be deleted.

**Additive-only contract**

- **FR-029**: The wizard MUST NOT modify any existing per-server-edit tab, admin page render dispatcher output outside the wizard branch, existing REST route, DB schema, seeded row, or WordPress option.
- **FR-030**: The wizard MUST write its authoritative data (server rows, access rules, abilities selections, enable-flag) through the plugin's existing data APIs — no direct DB queries in wizard code.

### WordPress Requirements

**PHP Version**: PHP 8.1+ (matches plugin baseline)
**WordPress Version**: 6.9+
**Multisite**: Single-site only (matches plugin baseline; per-site scoping remains implicit — no multisite-network entry point)
**Required Plugins / Packages**: `wpboilerplate/wpb-access-control` (existing), `wordpress/mcp-adapter` (existing) — both already hard-required by the plugin
**Optional Integrations**:
- `acrossai-abilities-manager` (WordPress.org plugin) — step 3 renders promo state when absent, real ability count when active
- `acrossai-pro` (paid add-on) — step 5 Connectors card renders promo/activate/active per install state
- Wizard MUST degrade gracefully in every combination of present/absent optional integrations

### Module Placement

**PHP Class(es)**:
- `admin/Partials/QuickSetup/QuickSetupPage.php` → namespace `AcrossAI_MCP_Manager\Admin\Partials\QuickSetup` — hijacks the plugin page render and emits the React mount div (renders admin HTML)
- `admin/Partials/QuickSetup/AdminBarEntry.php` → same namespace — registers the admin bar node
- `admin/Partials/QuickSetup/ActivationRedirect.php` → same namespace — consumes the activation signal on `admin_init` and issues `wp_safe_redirect` to the wizard step 1 URL under the FR-004 guards
- `includes/REST/QuickSetupController.php` → namespace `AcrossAI_MCP_Manager\Includes\REST` — context-neutral REST controller

**Hook Registration**: All `add_action` / `add_filter` calls for this feature MUST be wired in `includes/Main.php` via `define_admin_hooks()` / `define_public_hooks()` per the plugin's Constitution rule. No hook wiring inside class constructors or class bodies.

### Admin UI Requirements

**New screen** (created after constitution ratification):

- Forms MUST use `DataForm` (from `@wordpress/dataviews`) — the server-create inline form and the access-control editor MUST NOT be hand-rolled.
- Lists/tables MUST use `DataViews` (`@wordpress/dataviews`) — the server picker on step 1 MUST use it (or the plugin's existing shared component that already wraps it).
- `DataForm` MUST handle field validation, inline error display, and submission state.

**Pre-approved WP_List_Table exception** — not applicable here. The wizard REPLACES the list-table view when active; it does not extend it.

**Pre-approved Connector picker card exception** — not applicable here. The wizard's step 5 method cards are new UI, not part of the AI Connectors tab; they MUST use DataForm-compatible primitives.

**Additional wizard-shell requirements**:

- The wizard MUST match the visual design contract in `docs/quick-setup-design-brief.md` — every screen, every conditional state, every hardcoded copy string is authoritative in that document.
- The wizard MUST not leak styles to other admin pages: all styles MUST be scoped under a single CSS class on the wizard root element.
- The wizard's asset bundle MUST NOT load on any admin page outside the wizard URL.
- The wizard MUST meet **WCAG 2.1 Level AA** (matches WordPress core's own accessibility standard). Implementation MUST use the `@wordpress/components` primitives' built-in a11y affordances plus explicit focus-management and ARIA live-region wiring per FR-010a. Any custom (non-primitive) element MUST document its a11y strategy in the plan phase.

### REST API Contract

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| `GET` | `/wp-json/acrossai-mcp-manager/v1/quick-setup/state` | `manage_options` | Returns the full wizard state snapshot: server list, per-server settings, abilities count, plugin activation state for optional integrations, and per-user scratchpad. |
| `POST` | `/wp-json/acrossai-mcp-manager/v1/quick-setup/step` | `manage_options` | Persists per-step answers to the per-user scratchpad and delegates authoritative writes to existing data APIs (server create/update, access-control rule, abilities toggle). Body: `{ step, data }`. |
| `POST` | `/wp-json/acrossai-mcp-manager/v1/quick-setup/complete` | `manage_options` | Clears the per-user scratchpad. Returns 204 on success. |

**`permission_callback` rule**: `__return_true` is NOT permitted on any of these routes. Every route's permission callback MUST return a boolean derived from `current_user_can( 'manage_options' )`. Every mutating route (`POST`) MUST additionally verify a fresh `X-WP-Nonce` (`wp_rest`).

### Database / Storage

**WordPress options/meta API**:

- Site-level transient: `acrossai_mcp_manager_quick_setup_do_redirect` (value `'1'`, 30-second TTL). Set inside the plugin activation hook; consumed and deleted by `ActivationRedirect::maybe_redirect()` on the next `admin_init`. The short TTL prevents stale-redirect surprises if the admin_init consumer never runs (e.g., activation via WP-CLI without a subsequent admin request).
- Per-user transient: `acrossai_mcp_manager_quick_setup_state_{user_id}` (30-minute TTL). Stores in-flight step answers so reload restores position; deleted on wizard completion.

**Custom DB table**: None. The wizard writes authoritative data through existing tables via existing APIs (`MCPServerQuery`, wpb-ac `RuleQuery`, F017 abilities controller).

**No persistent storage** beyond the two transients above.

### Security Checklist

*(Derived from Constitution §III — verify all that apply)*

- [ ] All form/AJAX handlers verify nonce via `wp_verify_nonce()` or `check_ajax_referer()` — not currently applicable (the wizard has no server-side form submissions outside the nonce-verified REST controller, and no admin-post handlers). Any future admin-post handler MUST verify a nonce.
- [ ] All admin page renders check `current_user_can('manage_options')` — applies to the wizard render hijack.
- [ ] All REST routes have explicit `permission_callback` — no `__return_true` on mutating routes. Every wizard REST route uses boolean permission callbacks.
- [ ] All user input sanitized at system boundary with most-specific function (`sanitize_text_field()`, `absint()`, `sanitize_title()`, etc.) — applies to server-create form fields and step-data payloads.
- [ ] All output escaped at point of rendering with most-specific function (`esc_html()`, `esc_attr()`, `esc_url()`) — applies to any PHP-rendered strings in the wizard page shell + notice.
- [ ] All DB queries use `$wpdb->prepare()` — no raw interpolated queries. The wizard writes exclusively through existing APIs, but any read helper it adds MUST follow the rule.
- [ ] OAuth tokens / Application Passwords stored hashed — not applicable; the wizard does not create or store credentials directly.
- [ ] File uploads — not applicable; no upload capability in this feature.

### Key Entities *(include if feature involves data)*

- **Wizard Scratchpad**: transient per-user record capturing `{ server_id, current_step, method, per-step-answers }`. Ephemeral (30-min TTL); no schema; not queryable across users; deleted on completion.
- **Activation Redirect Signal**: single site-level flag capturing "an activation just happened and the next admin page load should redirect to the wizard". Ephemeral (30-second TTL); consumed exactly once by the `admin_init` handler that serves the redirect.
- **MCP Server**: existing entity — the wizard's read + write target on step 1. No shape change.
- **Access Control Rule**: existing entity (wpb-ac) — the wizard's read + write target on step 2. No shape change.
- **Ability Selection**: existing entity — the wizard's write target on step 3 (via F017 API). No shape change.

---

## Success Criteria *(mandatory)*

### Definition of Done Gates

All of the following MUST pass before this feature is considered complete:

- [ ] PHPCS validation: zero errors and zero warnings (`vendor/bin/phpcs`)
- [ ] PHPStan level 8: zero errors (`vendor/bin/phpstan`)
- [ ] ESLint: zero errors (`npm run lint:js`)
- [ ] PHPUnit tests written and passing for the REST controller, admin bar entry, activation-redirect handler (including bulk-activation + multisite-network-activation guard behavior), and the wizard render hijack
- [ ] Security checklist above: all applicable items verified
- [ ] All hooks wired in `Main.php` — none in class constructors or bodies
- [ ] All wizard admin UI uses `DataForm` / `DataViews` per Constitution §UI-Contract (server picker + server-create form + AC editor)
- [ ] No code duplication — the access-control editor is imported from the existing tab component, not re-authored
- [ ] All new functions, classes, hooks, transients, and REST routes prefixed with `acrossai_mcp_`
- [ ] `npm run validate-packages` passes
- [ ] Wizard asset bundle does NOT load on any admin page other than the wizard URL (verified by DevTools Network tab on the plugin list-table page)

### Measurable Outcomes

- **SC-001**: A first-run admin completes the wizard end-to-end in under 3 minutes (measured on an install with no existing servers, no Abilities Manager, no AcrossAI Pro).
- **SC-002**: The wizard reduces the click-count from initial setup to a working MCP client connection by ≥50% compared to configuring via the existing 11-tab per-server-edit page. (Baseline: current tabs; target: ≤10 clicks in the wizard vs. ≥20 in the tabs.)
- **SC-003**: 100% of REST routes return HTTP 403 or 401 when called by a non-`manage_options` user.
- **SC-004**: 100% of `POST` REST routes reject requests with a missing or invalid `X-WP-Nonce` header.
- **SC-005**: Browser reload on any step preserves prior-step answers, as measured by re-fetching the state snapshot and observing identical values.
- **SC-006**: When the selected server is already enabled, step 4 does not render at any point in the flow (forward or back), as measured by DOM inspection.
- **SC-007**: The wizard asset bundle (`quick-setup.{js,css}`) does not load on the plugin's list-table view or on any per-server-edit tab, as measured by the DevTools Network tab.
- **SC-008**: Post-implementation grep confirms zero non-additive edits under `admin/Partials/ServerTabs/`, `includes/REST/` (except the new controller), or `includes/Database/` — verifying the additive-only contract.
- **SC-009**: 90% of admins reaching the completion screen click "Go to server dashboard" or "Set up another server" (as opposed to Dismiss), indicating the wizard successfully bridges to next-action.
- **SC-010**: A keyboard-only walkthrough from step 1 through the completion screen succeeds without any mouse interaction. axe DevTools scan on every step reports zero WCAG 2.1 AA violations.
- **SC-011**: An admin who activates the plugin from the WordPress plugins list lands on the wizard's step 1 URL as the very next page — no intermediate settings page, no manual click required. Bulk activation and network-wide activation do NOT trigger the redirect (verified with two plugins activated together + `wp plugin activate --network`).

---

## Assumptions

- WordPress Application Passwords feature is enabled at the WP install level (baseline assumption for the entire plugin, not specific to this feature).
- Both `wpboilerplate/wpb-access-control` and `wordpress/mcp-adapter` are present as composer-hard-required dependencies; wizard behavior when they are missing is out of scope (a broader plugin-boot degradation path handles that already).
- The optional add-ons (`acrossai-abilities-manager`, `acrossai-pro`) may or may not be present; every wizard step MUST render a defined behavior for each combination.
- Multisite support is out of scope for this increment. The wizard is single-site only; per-site admins access it independently.
- Text-domain internationalization uses `'acrossai-mcp-manager'` — the standard plugin domain. Every user-facing string in the wizard MUST be translatable.
- The wizard does not attempt to remember which method the admin picked on prior runs; step 5 always starts unselected. Rationale: the admin's "which client did I set up last time?" question is answered by the completion-screen summary of the *current* run and by the plugin's regular admin surfaces, not by wizard-side state.
- The wizard does not gate itself on prior completion — an admin who has already run it once can re-run it any number of times via the admin bar chip.
- Step 5's "picked exactly one method" behavior is a UX simplification, not a data constraint. Nothing in the wizard prevents an admin from configuring multiple methods for the same server via the regular tabs; the wizard just guides them through one at a time.
- The activation redirect (FR-003) fires on **every** activation, not just the first-install activation. WordPress's `register_activation_hook` cannot distinguish first-install from re-activation, and re-activation typically indicates the operator wants a fresh setup pass anyway. Admins who don't want the redirect can activate via WP-CLI (`wp plugin activate acrossai-mcp-manager`), which suppresses admin-page redirects by design.
- The wizard does **not** emit `do_action` observability hooks on step-advance, completion, or abandonment. Sibling features (F015 Access Control, F042 Transport Default) do emit such hooks, but the wizard is an interactive admin surface (not a security or dispatch boundary) and adding hooks now would speculatively lock in payload shapes. Any future need for wizard-level observability can be added as a follow-up feature without breaking this contract.
