# Feature Specification: [FEATURE NAME]

**Feature Branch**: `[###-feature-name]`
**Created**: [DATE]
**Status**: Draft
**Input**: User description: "$ARGUMENTS"

## User Scenarios & Testing *(mandatory)*

<!--
  IMPORTANT: User stories must be PRIORITIZED as user journeys ordered by importance.
  Each story must be INDEPENDENTLY TESTABLE — if you implement only one of them you
  still have a viable MVP that delivers value.

  Assign priorities (P1, P2, P3, …) where P1 is the most critical.
  Think of each story as a standalone slice that can be:
    - Developed independently
    - Tested independently (via WP-CLI, manual admin test, or PHPUnit)
    - Demonstrated to the site administrator independently
-->

### User Story 1 - [Brief Title] (Priority: P1)

[Describe the site-administrator or AI-client journey in plain language]

**Why this priority**: [Explain the value and why it has this priority level]

**Independent Test**: [How this can be tested independently — e.g., "Navigate to Settings > MCP Manager, enable server X, verify REST route responds 200"]

**Acceptance Scenarios**:

1. **Given** [initial WP state], **When** [admin action or REST call], **Then** [expected outcome]
2. **Given** [initial WP state], **When** [admin action or REST call], **Then** [expected outcome]

---

### User Story 2 - [Brief Title] (Priority: P2)

[Describe the journey in plain language]

**Why this priority**: [Value and priority rationale]

**Independent Test**: [How to test independently]

**Acceptance Scenarios**:

1. **Given** [initial state], **When** [action], **Then** [expected outcome]

---

[Add more user stories as needed, each with an assigned priority]

### Edge Cases

- What happens when the MCP adapter / wpb-access-control plugin is absent?
- What happens when the user lacks `manage_options` capability?
- What happens when a DB query fails or returns no rows?
- How does the feature degrade gracefully when [optional integration] is unavailable?

---

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: [Specific capability — e.g., "Site admin MUST be able to enable/disable an MCP server"]
- **FR-002**: [Specific capability — e.g., "System MUST validate nonce before processing form submission"]
- **FR-003**: [Specific capability — e.g., "REST route MUST return 403 for unauthenticated requests"]

*Mark unclear requirements:*
- **FR-XXX**: [NEEDS CLARIFICATION: describe what is missing]

### WordPress Requirements

**PHP Version**: PHP 8.0+ (plugin supports 7.4 minimum; constitution target is 8.0)
**WordPress Version**: 6.9+
**Multisite**: [Supported | Single-site only — justify in plan if single-site]
**Required Plugins / Packages**: [e.g., `wpboilerplate/wpb-access-control`, `wordpress/mcp-adapter` | N/A]
**Optional Integrations**: [e.g., `wpb-access-control` — must degrade gracefully if absent | N/A]

### Module Placement

**PHP Class(es)**:
- `[admin/Partials/ClassName.php]` → namespace `AcrossAI_MCP_Manager\Admin\Partials` — if this class calls `add_menu_page()`, enqueues assets, or renders admin HTML
- `[includes/Module/ClassName.php]` → namespace `AcrossAI_MCP_Manager\Includes\[Module]` — if context-neutral
- `[public/Partials/ClassName.php]` → namespace `AcrossAI_MCP_Manager\Public\Partials` — if frontend-facing

**Hook Registration**: All `add_action`/`add_filter` calls for this feature MUST be wired in `includes/Main.php` via `define_admin_hooks()` or `define_public_hooks()`.

### Admin UI Requirements

<!-- Delete the option that does not apply -->

**New screen** (created after constitution ratification):
- Forms MUST use `DataForm` (exported from `@wordpress/dataviews`) — no custom form HTML
- Lists/tables MUST use `DataViews` (`@wordpress/dataviews`) — no custom table HTML
- `DataForm` MUST handle: field validation, inline error display, submission state

**Pre-approved WP_List_Table exception** (MCP Manager parent menu only):
- The `?page=acrossai_mcp_manager` screen uses `WP_List_Table` — this exception is pre-ratified
- No new screens may extend this exception without a constitution amendment

### REST API Contract

<!--
  Fill this section if this feature adds or modifies REST routes.
  Delete if the feature has no REST surface.
-->

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| `GET` | `/wp-json/acrossai-mcp/v1/[resource]` | `manage_options` | [Description] |
| `POST` | `/wp-json/acrossai-mcp/v1/[resource]` | `manage_options` | [Description] |

**`permission_callback` rule**: `__return_true` is permitted ONLY on public read-only routes. All mutating routes and admin-only routes MUST check capability.

### Database / Storage

<!-- Choose the applicable option and delete the rest -->

**WordPress options/meta API** (preferred for simple data):
- Option name: `acrossai_mcp_[option_name]`
- Data shape: [describe]

**Custom DB table** (only when options/meta cannot fit the model — justify here):
- Table: `{wpdb->prefix}acrossai_mcp_[table_name]`
- Justification: [why options/meta are insufficient]
- Activation hook: `register_activation_hook()` creates the table

**No persistent storage**: N/A

### Security Checklist

*(Derived from Constitution §III — verify all that apply to this feature)*

- [ ] All form/AJAX handlers verify nonce via `wp_verify_nonce()` or `check_ajax_referer()`
- [ ] All admin page renders check `current_user_can('manage_options')` (or more granular capability)
- [ ] All REST routes have explicit `permission_callback` — no `__return_true` on mutating routes
- [ ] All user input sanitized at system boundary with most-specific function (`sanitize_text_field()`, `absint()`, etc.)
- [ ] All output escaped at point of rendering with most-specific function (`esc_html()`, `esc_attr()`, `esc_url()`, etc.)
- [ ] All DB queries use `$wpdb->prepare()` — no raw interpolated queries
- [ ] OAuth tokens / Application Passwords stored hashed (SHA-256 minimum) — never plaintext
- [ ] File uploads (if any) validated for MIME type, extension, and size before processing

### Key Entities *(include if feature involves data)*

- **[Entity]**: [What it represents, key attributes without implementation detail]

---

## Success Criteria *(mandatory)*

### Definition of Done Gates

All of the following MUST pass before this feature is considered complete:

- [ ] PHPCS validation: zero errors and zero warnings (`vendor/bin/phpcs`)
- [ ] PHPStan level 8: zero errors (`vendor/bin/phpstan`)
- [ ] ESLint: zero errors (`npm run lint:js` if JS added)
- [ ] PHPUnit tests written and passing for all new PHP logic
- [ ] Security checklist above: all applicable items verified
- [ ] All hooks wired in `Main.php` — none in class constructors
- [ ] All new admin UI uses DataForm/DataViews (unless WP_List_Table exception applies)
- [ ] No code duplication — shared logic extracted to `includes/Utilities/`
- [ ] All functions, hooks, and classes prefixed with `acrossai_mcp_`
- [ ] `npm run validate-packages` passes

### Measurable Outcomes

- **SC-001**: [Measurable outcome — e.g., "Site admin can toggle MCP server in under 3 clicks"]
- **SC-002**: [Measurable outcome — e.g., "All REST routes return 403 for unauthenticated requests"]

---

## Assumptions

- [Assumption about WordPress environment — e.g., "WP Application Passwords feature is enabled"]
- [Assumption about optional integrations — e.g., "wpb-access-control may or may not be active; feature degrades gracefully if absent"]
- [Scope boundary — e.g., "Multisite support is out of scope for this increment"]
