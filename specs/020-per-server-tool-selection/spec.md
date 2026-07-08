# Feature Specification: Per-Server Tool Selection

**Feature Branch**: `020-per-server-tool-selection`
**Created**: 2026-07-09
**Status**: Draft
**Input**: User description: See `docs/planings-tasks/020-per-server-tool-selection.md`

## Clarifications

### Session 2026-07-09

- Q: When an MCP server row is deleted from `wp_acrossai_mcp_servers`, what happens to that server's rows in `wp_acrossai_mcp_server_tools`? → A: Auto-cleanup on server deletion via a WordPress action hook. Feature 020 owns this cleanup — the tool selection rows for the deleted `server_id` are removed atomically after the parent server is deleted.
- Q: If the operator makes pending Add / Remove edits and then reloads the browser tab (or navigates away and back) without clicking Save, what happens to the draft? → A: The draft is discarded. Every mount fetches fresh server state; local drafts are ephemeral to the current mount. Reload = clean slate. No `sessionStorage` rehydration, no `beforeunload` prompt. **Superseded 2026-07-09 post-implementation** by the optimistic-per-toggle pivot (FR-009 rewrite) — draft state no longer exists, so the reload question is moot.
- Q: When the plugin is fully uninstalled (Plugins → Delete), what happens to `wp_acrossai_mcp_server_tools` and its `db_version_key` option? → A: Dropped and deleted, **but only when the operator has opted in** via the `acrossai_mcp_uninstall_delete_data` flag (default `0` = preserve). `uninstall.php` runs `DROP TABLE IF EXISTS wp_acrossai_mcp_server_tools` and `delete_option( 'acrossai_mcp_server_tools_db_version' )` **below the opt-in gate**, matching the convention already established by the four existing BerlinDB tables (Feature 011) and enforced by `DEC-UNINSTALL-OPT-IN-GATE` (Feature 012). Plugin deactivation deletes neither. Uninstall without the opt-in flag preserves both.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Curate the tool set for an MCP server (Priority: P1)

A site administrator is configuring an MCP server they've registered on this
site. They open the **Tools** tab inside the "Edit MCP Server" screen and see
two columns side-by-side: on the left, every WordPress ability registered on
this site (each with a name, a slug, a type badge, and a short description);
on the right, the three built-in `mcp-adapter/*` protocol tools shown as
always-available at the top plus any abilities the operator has already
curated as tools for this specific server. The curated section starts empty
on a fresh install. The administrator picks the abilities they want AI
clients to be able to call — say, "Create Post", "List Media", and "Approve
Comment" — by clicking each row's **Add** button; each click **immediately
POSTs** the new tool set to the server (optimistic-per-toggle) and a brief
"Saving…" indicator confirms the commit. The counter ticks up
("3 of 16 abilities added as tools · 3 built-in always available"). The
next time an AI client connects to this server, those three curated
abilities plus the three built-ins appear as callable tools; every other
ability returns 403 from the enforcement gate.

**Why this priority**: This is the entire feature. Without this journey the
Tools tab has no user-visible value — the current tab shows a static
reference table that can't be configured. Operators cannot control what an
AI client sees on this server without shipping this workflow.

**Independent Test**: Navigate to
`?page=acrossai_mcp_manager&action=edit&tab=tools` on a fresh install, add
one ability from the left column, confirm the "Saving…" indicator appears
briefly and then clears, then reload the page — the added ability must
still be in the right column. Then call
`GET /wp-json/acrossai-mcp-manager/v1/servers/{id}/tools` and confirm the
response `tools` array contains that ability's slug. Then invoke an MCP
tool call for a NOT-added ability against that server and verify HTTP 403
with `acrossai_mcp_tool_not_added` (SC-012).

**Acceptance Scenarios**:

1. **Given** the operator has a registered MCP server and the WordPress
   Abilities API is available, **When** they open the Tools tab, **Then**
   the left column lists every registered ability except the three built-in
   MCP-adapter protocol tools, and the right column shows the three
   built-ins at the top with an empty "Curated tools" section below.
2. **Given** the operator has clicked **Add** on an ability, **When** the
   POST resolves successfully, **Then** the ability appears in the right
   column's "Curated tools" section, the counter increments, and the
   "Saving…" indicator clears. No explicit "save" action is required —
   the click IS the commit (FR-009).
3. **Given** the operator has clicked **Add** on an ability, **When** the
   POST fails (403 / 400 / 500), **Then** the ability rolls back to the
   left column, an error notice surfaces with the failure reason, and the
   server-side tool set remains unchanged.
4. **Given** the operator has zero curated tools on a server, **When**
   they view the Tools tab, **Then** the "Curated tools" section shows a
   "No tools added yet" empty state and an amber banner explains that
   AI clients can still discover the built-in protocol tools but cannot
   execute any WordPress ability until at least one is added.
5. **Given** two operators are clicking Add / Remove on the same server's
   tools tab simultaneously, **When** the second click POSTs after the
   first, **Then** the second POST's final set wins deterministically
   (serialized on `SELECT ... FOR UPDATE` in `Query::replace_set()`) — no
   partial merge, no set-union superset.

---

### User Story 2 - Reset the curated tool set in one click (Priority: P2)

The site administrator has curated a large tool set over time and wants to
start fresh — either because the exposure surface has grown too permissive
for a production hand-off, or because they want to reconfigure from
scratch. They click **Reset** in the right column header; the curated
list clears immediately (the three built-in `mcp-adapter/*` protocol tools
remain always-available). They can then re-add just the subset they want.

**Why this priority**: One-click reset preserves the "start fresh"
affordance without requiring many individual Remove clicks. The original
"Add all →" bulk-add button was retired at operator request — bulk-add's
value is lower than reset's because operators rarely want *every* ability
exposed at once but do periodically want to clear.

**Independent Test**: With N curated tools in the right column, click
**Reset**, verify the right column's curated section empties (built-ins
stay), the counter drops to `0` (excluding built-ins), and reload confirms
the empty set persisted server-side.

**Acceptance Scenarios**:

1. **Given** the right column contains M curated tools (plus the 3
   always-available built-ins), **When** the operator clicks **Reset**,
   **Then** the M curated tools disappear immediately, the built-ins
   remain, and a POST to `/servers/{id}/tools` with `{ tools: [] }`
   commits the change.
2. **Given** the curated set is already empty, **When** the operator
   views the tab, **Then** the **Reset** button is disabled (no state
   to reset).
3. **Given** a POST rejects the Reset (e.g., server 500), **When** the
   error surfaces, **Then** the curated tools reappear locally (rollback)
   and an error notice is shown.

---

### User Story 3 - Find a specific ability by name or description (Priority: P3)

The site administrator knows they want to expose an ability related to
"comment moderation" but there are 40+ abilities registered. They type
"comment" into the search box above the left column, watch the pool narrow
to the two matching abilities ("Approve Comment", "List Comments"), and add
the one they want.

**Why this priority**: Nice-to-have that scales the primary workflow to
large ability catalogs. Without it, operators would scroll through dozens
of rows. Below Story 2 because bulk actions cover the "select many" case
directly.

**Independent Test**: Type a substring of a known ability's label into the
search box; verify the pool narrows to the matches only. Clear the search;
verify the full pool returns.

**Acceptance Scenarios**:

1. **Given** the left column has 16 abilities, **When** the operator types
   "post" into the search box, **Then** the pool shrinks to rows whose
   name, label, description, or category matches "post" (case-insensitive).
2. **Given** a search term matches zero abilities, **When** the pool would
   otherwise be empty, **Then** the left column shows "No abilities match
   your search."
3. **Given** every registered ability is already added as a tool, **When**
   the operator opens the tab, **Then** the left column shows "Every
   ability has been added as a tool."

---

### Edge Cases

- **Server disabled**: When the MCP server itself is disabled on the
  Overview tab, the Tools tab renders an amber warning notice explaining
  that enabling the server is required for tools to reach clients, AND
  the picker remains fully editable so the operator can prepare the
  tool set in advance. The notice explicitly informs the operator that
  the curated selection will take effect the moment the server is
  toggled on.
- **WordPress Abilities API absent**: When `wp_get_abilities()` isn't
  available (the WordPress feature hasn't landed, or a required plugin
  is deactivated), the Tools tab renders an inline error notice
  explaining the dependency and does not attempt to load the picker.
- **Stale rows** (previously-added ability was unregistered): The right
  column continues to show the row so the operator can see it exists and
  remove it explicitly. It is NOT auto-deleted. The removed ability's
  metadata may be incomplete — display the raw slug and mark the type
  badge as unknown.
- **Invalid slug submitted to REST**: Any slug in the submitted set that
  is not a currently-registered ability rejects the entire save with a
  400 error and a message listing the invalid slugs. No partial writes.
- **Zero tools after save**: The empty state remains, and the warning
  banner ("Connected AI clients won't be able to discover or execute
  any abilities…") is visible until the operator saves a non-empty set.
- **POST fails mid-request**: The optimistic UI update rolls back to the
  pre-click state (per FR-009), an error `<Notice>` surfaces explaining
  the failure, and the server-side tool set is unchanged. The operator
  can immediately retry the click.
- **Parent MCP server deleted**: When an MCP server row is deleted, all
  associated tool selection rows in `wp_acrossai_mcp_server_tools` are
  removed automatically via a hook on the server-deletion action. The
  operator does not need to clean up tool selections before deleting the
  server, and a subsequently-recreated server does not inherit stale
  selections from a prior deletion.
- **Page reload**: Every operator action commits immediately (FR-009),
  so a page reload always shows the same server-side state as the last
  successful POST. There is no draft to lose. If a POST was in-flight
  when the tab was closed, the outcome depends on whether the server
  received and processed the request before the connection dropped —
  the reload will show whichever state ultimately committed.

---

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The Tools tab MUST be reachable at
  `?page=acrossai_mcp_manager&action=edit&tab=tools` and appear at
  priority slot 50 in the per-server tab bar (unchanged from today).
- **FR-002**: Site admin MUST be able to see every registered WordPress
  ability in the left column, except the three built-in MCP-adapter
  protocol tools (`mcp-adapter/discover-abilities`,
  `mcp-adapter/get-ability-info`, `mcp-adapter/execute-ability`), which
  are hidden from the left column. See FR-025 for where those built-in
  tools DO appear in the UI (top of the right column, always-visible
  and non-removable).
- **FR-003**: Site admin MUST be able to add an ability to this server's
  tool set by clicking the row's **Add** button; the row moves
  optimistically from the left column to the right column and the
  server commit is triggered automatically (see FR-009 for the
  per-click POST semantics).
- **FR-004**: Site admin MUST be able to remove an ability from this
  server's tool set by clicking the row's **Remove** button; the row
  moves optimistically from the right column back to the left column
  and the server commit is triggered automatically.
- **FR-005**: **RETIRED** — the "Add all →" bulk-add button was removed
  post-implementation per operator request. The mockup's original bulk-add
  affordance is no longer part of the shipped UX.
- **FR-006**: Site admin MUST be able to clear the entire curated tool set
  in one action via the **Reset** button in the right column header.
  Clicking Reset immediately POSTs an empty tools array to the server
  (the built-in `mcp-adapter/*` protocol tools are unaffected — they
  remain always-available and are not part of the curated set).
- **FR-007**: A search input above the left column MUST filter the pool
  by case-insensitive substring match against the ability's name, label,
  description, or category. Search does NOT filter the right column.
- **FR-008**: A counter beside the tab heading MUST display "N of M
  abilities added as tools" where N is the number in the right column
  and M is the total registered ability count (post-exclusion of the
  three protocol tools).
- **FR-009**: Each Add / Remove / Reset action MUST commit to the server
  immediately as an **optimistic-per-toggle POST** — the UI reflects the
  change instantly, then a POST fires with the new full tool set. On
  success, the local state reconciles against the server response. On
  failure (4xx / 5xx / network), the UI **rolls back** the optimistic
  update to the pre-click state and an error notice surfaces.
- **FR-010**: Every POST from Add / Remove / Reset MUST send the
  intended full desired tool set (replace-all semantics — server diffs
  the incoming set against stored state and applies inserts + deletes
  atomically inside the `SELECT ... FOR UPDATE` transaction from FR-030).
- **FR-011**: While a POST is in-flight, an inline "Saving…" indicator
  MUST appear beneath the picker to give the operator visual feedback.
  The **Save changes** and **Cancel** buttons from the original mockup
  are **RETIRED** — the picker's own actions ARE the commit.
- **FR-012**: While a POST is in-flight, per-row Add / Remove buttons
  and the Reset button MUST be disabled to prevent double-click race
  conditions. Once the POST resolves (success or rollback), the buttons
  re-enable.
- **FR-013**: The tool selection MUST be scoped per-server — the tool
  set on server A MUST NOT affect the tool set on server B.
- **FR-014**: The tool selection MUST persist across page reloads,
  browser restarts, and WordPress reboots. Storage is a first-class
  concern of this feature.
- **FR-015**: Concurrent edits from multiple operators use last-writer-
  wins semantics — the most recent successful save replaces the whole
  tool set. No merge, no locking, no versioning.
- **FR-016**: When zero tools are currently saved, the right column
  MUST show a "No tools added yet" empty state and an inline warning
  banner MUST explain that connected AI clients cannot discover or
  execute any abilities on this server.
- **FR-017**: The tab MUST display an informational banner that names
  the `wordpress/mcp-adapter` package as the delivery mechanism and
  cross-links to the Abilities tab where the source abilities are
  registered.
- **FR-018**: When the MCP server is disabled, the tab MUST show a
  disabled-server warning notice AND the picker MUST remain fully
  editable so the operator can prepare the tool set in advance. The
  notice text MUST explicitly inform the operator that curated changes
  take effect the moment the server is enabled. Persistence works
  regardless of server state — `POST /tools` accepts writes against
  disabled servers and the enforcement gate consults the same
  `wp_acrossai_mcp_server_tools` rows once the server is toggled on.
- **FR-019**: When the WordPress Abilities API (`wp_get_abilities()`)
  is unavailable, the tab MUST show an inline error notice explaining
  the dependency and hide the picker; the page MUST NOT fatal.
- **FR-020**: When a WordPress ability that was previously added to the
  tool set is no longer registered, the row MUST remain visible in the
  right column with the raw slug (so the operator can see it and
  remove it explicitly). No auto-cleanup.
- **FR-021**: The REST endpoint that lists and updates tool sets MUST
  reject callers without site-admin capability (HTTP 403). Never
  `__return_true`.
- **FR-022**: The REST endpoint that updates the tool set MUST validate
  every submitted slug against the currently-registered ability list.
  If any submitted slug is invalid, the entire save MUST be rejected
  with HTTP 400 and a message listing the invalid slugs. No partial
  writes.
- **FR-023**: When the tool set changes on save, the system MUST fire
  a WordPress action once per applied add and once per applied remove
  (payload: server ID, ability slug, operation). This is the public
  extensibility surface for third-party integrations (audit logs,
  metrics, notifications).
- **FR-024**: The tab MUST retain its slug (`tools`), label (`Tools`),
  and priority slot (`50`) so bookmarks and the F019 third-party tab
  filter continue to function unchanged.
- **FR-025**: The three built-in MCP-adapter protocol tools
  (`mcp-adapter/discover-abilities`, `mcp-adapter/get-ability-info`,
  `mcp-adapter/execute-ability`) MUST NOT appear in the left "All
  abilities" pool — they are protocol plumbing owned by the adapter
  package and are not per-server configurable. However, they MUST
  appear in the right "Added as tools" column under an "Always
  available (built-in)" section header at the top, above the
  operator-curated "Curated tools" section. Built-in rows are
  visually distinct (muted amber "Built-in" badge instead of the
  Tool / Prompt / Resource palette, lock icon instead of the ✓
  checkmark, "Built-in" label instead of a Remove button) and are
  NOT operator-editable. The counter badge in the right column
  header includes them (`added.size + 3`). The top-of-tab counter
  reads "N of M abilities added as tools · 3 built-in always
  available" so operators see both metrics at a glance. Built-in
  slugs are NEVER sent in POST payloads — they exist only in the UI
  and are enforced-through by the ToolExposureGate protocol-bypass
  step regardless of storage state.
- **FR-026**: When an MCP server is deleted from
  `wp_acrossai_mcp_servers`, all rows in `wp_acrossai_mcp_server_tools`
  matching that `server_id` MUST be deleted atomically as part of the
  server-deletion flow. Feature 020 owns this cleanup by hooking the
  BerlinDB-native `mcp_server_deleted` action fired by
  `MCPServer\Query::delete_item()` (see `vendor/berlindb/core/src/Database/Kern/Query.php:2807-2823`).
  Both the single-row delete path
  (`admin/Partials/Settings.php:129`) and the bulk-delete path
  (`admin/Partials/Settings.php:223`) route through
  `delete_item()`, so this single hook covers both. The cleanup callback
  MUST no-op when the `mcp_server_deleted` payload's second argument
  (`bool $result`) is `false` — a failed server delete MUST NOT trigger
  cascade cleanup.
- **FR-027**: **RETIRED** — the optimistic-per-toggle pivot (FR-009)
  eliminated draft state entirely. Every operator action commits
  immediately, so there is no draft to lose across a page reload.
  Page reload behavior is now trivially defined by the mount fetching
  fresh server state; no `sessionStorage`, `localStorage`, or
  `beforeunload` prompt is needed because no operator work is ever
  local-only.
- **FR-029**: The Tools tab's per-server selection MUST be enforced at
  the MCP tool-call boundary. Feature 020 MUST wire a callback on the
  `mcp_adapter_pre_tool_call` filter at **priority 30** — stacking
  after F015 access control (priority 10) and F017 ability exposure
  (priority 20). The callback MUST honor **deny-precedence**: if the
  incoming filtered value is already a `WP_Error`, return it unchanged;
  never re-allow an already-denied ability. The callback MUST **fail
  open** on unresolvable `server_id` (matches D19 defensive fail-open
  pattern from F015 / F017). Behavior for each `(server_id, ability_slug)`
  pair: (a) if `ability_slug` is one of the three excluded protocol
  slugs, return incoming value unchanged (protocol tools are always
  callable); (b) if `ability_slug` IS present in
  `wp_acrossai_mcp_server_tools` for `server_id`, return incoming value
  unchanged (defer to F015/F017 for final say); (c) if `ability_slug`
  is NOT present, return
  `new \WP_Error( 'acrossai_mcp_tool_not_added', __( 'This tool is not enabled on this MCP server.', 'acrossai-mcp-manager' ), array( 'status' => 403 ) )`.
  Consequence: an empty tools set means every non-protocol ability
  returns 403 from F020's gate — matching the zero-added warning
  banner's UX promise. **List-time hiding** of unadded abilities from
  the MCP `tools/list` protocol endpoint is deferred to a follow-up
  feature (documented in Assumptions §"Enforcement is additive").
- **FR-030**: `Query::replace_set()` MUST be wrapped in an explicit DB
  transaction (`START TRANSACTION` / `COMMIT` / `ROLLBACK`). The
  transaction MUST take an **exclusive row-range lock** on all rows for
  `server_id` at the start of the transaction via
  `SELECT id FROM {prefix}acrossai_mcp_server_tools WHERE server_id = %d FOR UPDATE`
  before running the read-side `get_added_slugs()` snapshot. This
  serializes overlapping saves on the same server, so under concurrent
  POSTs to the same `/servers/{id}/tools`:
  - The first transaction acquires the row-range lock, runs its diff
    and writes, and commits.
  - The second transaction blocks on the lock until the first commits,
    then reads a fresh snapshot reflecting the first's writes, computes
    its diff against THAT snapshot, and applies it.
  - Final DB state = exactly the second-committing request's desired set.
    No set-union superset. No deadlock (single-direction serialization).
  - On lock-wait timeout (extreme contention), the second POST fails
    with HTTP 500 and the client can retry. Documented in the 500
    response contract.
  On transaction error (deadlock, lock-wait timeout, or downstream
  exception), all changes MUST roll back via `ROLLBACK`; no partial
  writes reach the DB. POST commits across DIFFERENT `server_id`
  values do NOT serialize with each other — the range lock is scoped
  to a single `server_id`.
- **FR-031**: The controller's `do_action( 'acrossai_mcp_tools_changed', ... )`
  fires MUST be individually wrapped in `try/catch`. An observer that
  throws MUST have its exception caught, logged via `error_log`, and
  MUST NOT bubble to the REST response. The DB write commits BEFORE
  the observer fires (per FR-023), so a broken observer never causes a
  successful save to appear as an HTTP 500 to the client. Remaining
  observers continue to fire after an isolated failure.
- **FR-028**: On full plugin uninstall (`uninstall.php`), the table
  `{wpdb->prefix}acrossai_mcp_server_tools` MUST be dropped and the
  option `acrossai_mcp_server_tools_db_version` MUST be deleted —
  **but only when the operator has explicitly opted in** by setting
  `acrossai_mcp_uninstall_delete_data` to `1` (default `0` = preserve).
  The `DROP TABLE` + `delete_option` statements MUST live BELOW the
  opt-in short-circuit at the top of `uninstall.php`, per
  `DEC-UNINSTALL-OPT-IN-GATE` (Feature 012). Feature 020 MUST NOT add
  a second gate — it reuses the existing one. Plugin deactivation MUST
  NOT delete either. Uninstall without the opt-in flag preserves both.
  Matches the convention already followed by the four existing BerlinDB
  tables.

### WordPress Requirements

**PHP Version**: PHP 8.0+
**WordPress Version**: 6.9+
**Multisite**: Supported — table is per-site (not global). Each site in a
network has its own tool selections.
**Required Plugins / Packages**: `wordpress/mcp-adapter` (already a
dependency), `berlindb/core: ^3.0.0` (already a dependency via F010/F011).
**Optional Integrations**: The WordPress Abilities API — feature degrades
gracefully with an error notice when absent.

### Module Placement

**PHP Class(es)**:

- `includes/Database/MCPServerTool/Table.php` — namespace
  `AcrossAI_MCP_Manager\Includes\Database\MCPServerTool` — BerlinDB Table
  subclass; instantiated at activation + request-time.
- `includes/Database/MCPServerTool/Schema.php` — same namespace — BerlinDB
  Schema.
- `includes/Database/MCPServerTool/Query.php` — same namespace — BerlinDB
  Query with singleton + bespoke `get_added_slugs()` +  `replace_set()`.
- `includes/Database/MCPServerTool/Row.php` — same namespace — BerlinDB
  Row.
- `includes/REST/ToolsController.php` — namespace
  `AcrossAI_MCP_Manager\Includes\REST` — REST controller singleton.
- `admin/Partials/ServerTabs/ToolsTab.php` — namespace
  `AcrossAI_MCP_Manager\Admin\Partials\ServerTabs` — existing file whose
  `render_body()` is rewritten. Slug, label, priority preserved.

**JS/CSS**:

- `src/js/tools.js` — new bundle producing `build/js/tools.js` +
  `build/js/tools.asset.php` (+ optional `build/js/tools.css`) via
  `@wordpress/scripts` webpack pipeline.

**Hook Registration**: All `add_action`/`add_filter` for this feature MUST
be wired in `includes/Main.php` via `define_admin_hooks()` (REST route
registration on `rest_api_init`, script enqueue on
`admin_enqueue_scripts`). Table subclass instantiated in
`Main::bootstrap_database_tables()` and `Activator::activate()`.

### Admin UI Requirements

**Existing per-server tab framework** (F013 / F019, pre-ratified):

- The Tools tab lives inside the `AbstractServerTab`-based Registry
  framework at `admin/Partials/ServerTabs/`.
- The tab's `render_body()` emits a React mount div; the actual UI is
  rendered by the `src/js/tools.js` bundle, not by PHP.
- The React app uses `@wordpress/element`, `@wordpress/components`,
  `@wordpress/api-fetch`, `@wordpress/i18n`, and `@wordpress/hooks`. No
  external UI libraries.
- The UI is a hand-rolled two-column shuttle picker matching the mockup
  at `tools-ui.zip → Tools Selection.dc.html`, NOT `@wordpress/dataviews`.
  This is a deliberate divergence from the F017 Abilities tab.

### REST API Contract

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| `GET` | `/wp-json/acrossai-mcp-manager/v1/servers/{server_id}/tools` | `manage_options` | Return the current tool slug set for `server_id`. Accepts `?include_abilities=1` to also return the full registered-ability catalog for cold-start rendering. |
| `POST` | `/wp-json/acrossai-mcp-manager/v1/servers/{server_id}/tools` | `manage_options` | Replace the tool slug set for `server_id` with the submitted set. Server-side diff + insert + delete. All-or-nothing validation. |

**`permission_callback` rule**: Both routes explicitly check
`current_user_can( 'manage_options' )`. `__return_true` is forbidden.

**Response shape (GET)**:

```json
{
  "tools": ["namespace/ability-slug", "…"],
  "abilities": [
    {
      "name": "namespace/ability-slug",
      "label": "Human label",
      "description": "Short description",
      "type": "Tool | Prompt | Resource | \"\"",
      "category": "Optional grouping label"
    }
  ]
}
```

The `abilities` array is included only when `?include_abilities=1` is
present.

**Request shape (POST)**:

```json
{ "tools": ["namespace/ability-slug", "…"] }
```

Full desired set (replace-all semantics). Duplicates within the request
are collapsed. Empty array is valid and results in an empty tool set.

### Database / Storage

**Custom DB table** (justified — see below):

- Table: `{wpdb->prefix}acrossai_mcp_server_tools`
- Columns: `id` (bigint PK), `server_id` (bigint, indexed),
  `ability_slug` (varchar 191), `created_at` (datetime), `updated_at`
  (datetime).
- Indexes: PRIMARY(id), UNIQUE(server_id, ability_slug), KEY(server_id).
- Row presence is the semantics — a row for `(server_id, ability_slug)`
  means "ability is added as a tool for this server". No `is_exposed`
  boolean column.
- Managed by BerlinDB with a phantom-version self-heal guard on
  `Table::maybe_upgrade()` (per DEC-BERLINDB-TABLE-REQUEST-BOOT
  established in F011).
- **Uninstall lifecycle**: `uninstall.php` drops the table and deletes
  the `db_version_key` option **only when the operator has opted in**
  via `acrossai_mcp_uninstall_delete_data === 1` — matches the
  preserve-by-default convention for the four existing BerlinDB tables
  and honors `DEC-UNINSTALL-OPT-IN-GATE`. Plugin deactivation does not
  delete either.

**Justification for a custom table over `wp_options`**:

- The dataset is a variable-length list per server (potentially dozens of
  rows per server on sites with rich ability catalogs). Storing this as
  a serialized option per server would cause autoload bloat and would
  prevent per-slug queries.
- BerlinDB is already the plugin's DB abstraction (F011 migrated four
  modules to it). Adding a fifth module is the low-friction path.
- The composite UNIQUE(server_id, ability_slug) constraint enforces
  correctness at the DB level (no duplicate rows possible even under
  race conditions).

### Security Checklist

*(Derived from Constitution §III — all applicable)*

- [x] All REST routes have explicit `permission_callback` checking
      `manage_options` — no `__return_true` on mutating routes.
- [x] All user input sanitized at system boundary with `sanitize_text_field()`
      for slug values, `absint()` for server IDs.
- [x] All output escaped at point of rendering with `esc_html__`, `esc_attr`,
      `esc_url`. React output is inherently escaped.
- [x] All DB writes go through BerlinDB's prepared-statement layer — no raw
      interpolated queries.
- [x] Nonce middleware from `@wordpress/api-fetch` seeded with
      `wp_create_nonce( 'wp_rest' )` for all POST calls.
- [x] Server-ID validation: every REST call MUST verify `server_id` resolves
      to a real row in `wp_acrossai_mcp_servers` — return 404 otherwise.
- [x] The public action (`acrossai_mcp_tools_changed`) payload contains
      server ID, slug, and operation only — no user IDs, IP addresses, or
      other PII.

### Key Entities

- **MCP Server**: The container being configured. Identified by an integer
  ID and a slug. Already exists in the plugin (`wp_acrossai_mcp_servers`).
- **Ability**: A registered WordPress ability, discovered via
  `wp_get_abilities()`. Has a name (namespaced slug), a human label, a
  short description, an MCP type (Tool / Prompt / Resource), and an
  optional category. This feature does not create or modify abilities —
  it only references them by slug.
- **Tool Selection**: The set of ability slugs the operator has added as
  callable tools for a specific server. Represented as row presence in
  `wp_acrossai_mcp_server_tools` — a row means "added", no row means
  "not added". No third state. When the parent server is deleted, all
  Tool Selection rows for that server are removed automatically (see
  FR-026).
- **Tool Change Event**: A WordPress action fired once per applied add
  and once per applied remove after each successful `POST /tools`
  commit. Payload: server ID, ability slug, operation
  ("added" | "removed"). Consumers: audit logs, metrics collectors,
  notification integrations.

---

## Success Criteria *(mandatory)*

### Definition of Done Gates

All of the following MUST pass before this feature is considered complete:

- [ ] PHPCS validation: zero errors and zero warnings (`vendor/bin/phpcs`).
- [ ] PHPStan level 8: zero errors (`vendor/bin/phpstan`).
- [ ] ESLint: zero errors (`npm run lint:js`).
- [ ] Stylelint: zero errors (`npm run lint:css`).
- [ ] PHPUnit tests passing for the new BerlinDB module and REST
      controller.
- [ ] Jest tests passing for the new React bundle helpers.
- [ ] Security checklist above: all applicable items verified.
- [ ] All hooks wired in `Main.php` — none in class constructors.
- [ ] All functions, hooks, and classes prefixed with `acrossai_mcp_`.
- [ ] `npm run build` produces `build/js/tools.js` + `build/js/tools.asset.php`.
- [ ] Zero references to the deleted `get_core_tools` and
      `render_tools_table` helpers anywhere in the codebase.
- [ ] Feature 017 (Abilities tab) files receive zero edits — verified
      by branch-level grep.

### Measurable Outcomes

- **SC-001**: A site admin can add three abilities as tools in under
  30 seconds starting from the empty state — no explicit save action
  required (optimistic-per-toggle POST commits each Add immediately).
- **SC-002**: 100% of REST calls without `manage_options` capability are
  rejected with HTTP 403.
- **SC-003**: Tool selection persists byte-for-byte across page reloads,
  WordPress reboots, and plugin deactivation/reactivation cycles.
- **SC-004**: Adding, removing, or bulk-editing tools requires zero page
  reloads — the operator sees changes reflected in the UI immediately.
- **SC-005**: When the saved tool set is empty, both the "No tools added
  yet" empty state AND the inline warning banner appear on the same page
  load — no click required to reveal the warning.
- **SC-006**: Configuring the tool set on server A never affects the tool
  set on server B — verified by side-by-side edit of two servers.
- **SC-007**: When the WordPress Abilities API is unavailable, the Tools
  tab renders a graceful error notice instead of a fatal or a blank
  screen (verified with the underlying capability programmatically
  removed).
- **SC-008**: When an ability that was previously added as a tool is
  unregistered from WordPress, its row remains visible in the right
  column with the raw slug so the operator can remove it — no automatic
  cleanup happens behind the operator's back.
- **SC-009**: A save operation on a 20-slug tool set completes in under
  1 second from click to UI refresh on a healthy local install.
- **SC-010**: The three MCP-adapter protocol tools
  (`mcp-adapter/discover-abilities`, `mcp-adapter/get-ability-info`,
  `mcp-adapter/execute-ability`) never appear in the selectable
  ability pool — verified by 100% string-match exclusion.
- **SC-011**: Two admins editing the same server's tool set concurrently
  produce a deterministic last-committer-wins final state. Under the
  optimistic-per-toggle POST model (FR-009), each Add / Remove / Reset
  click is a full-set replace serialized on the `SELECT ... FOR UPDATE`
  lock inside `Query::replace_set()`. Two overlapping POSTs MUST
  result in a DB state equal to exactly the second committer's request
  body — no lost writes, no set-union superset. Verified by a
  concurrency test in `tests/phpunit/Database/MCPServerTool/`.
- **SC-012**: An AI client attempting to call a WordPress ability that
  is NOT in the server's tools set MUST receive a 403 error at the
  mcp-adapter tool-call boundary. Clicking Remove on an ability in the
  Tools tab (which auto-commits per FR-009) MUST invalidate the
  client's ability to call it on the next tool invocation — no cache,
  no delay. Verified by integration test against the
  `mcp_adapter_pre_tool_call` filter.
- **SC-013**: A broken third-party observer (mu-plugin throwing inside
  `acrossai_mcp_tools_changed`) MUST NOT cause the REST POST to return
  500. The DB write commits, the observer error is `error_log`'d, and
  the REST response is 200 with the fresh tools list. Verified by the
  quickstart Step 5 rollback walkthrough in the current release;
  a PHPUnit test that registers a throwing observer (task T013) is
  scheduled as a follow-up.
- **SC-014**: When an MCP server is deleted via the admin UI (single or
  bulk), all rows in `wp_acrossai_mcp_server_tools` for that
  `server_id` MUST be removed. `SELECT COUNT(*) FROM
  wp_acrossai_mcp_server_tools WHERE server_id = <deleted_id>` MUST
  return `0` after the delete completes. Verified via the quickstart
  Step 7 walkthrough.

---

## Assumptions

- **WordPress Abilities API**: The plugin can rely on `wp_get_abilities()`
  when the WordPress feature or the required plugin providing it is
  active. When absent, the Tools tab gracefully degrades to an error
  notice — no fatal. This mirrors Feature 017's Abilities tab behavior.
- **BerlinDB is available at runtime**: Feature 011 migrated the four
  existing DB modules to BerlinDB and established the composer
  dependency + phantom-version-guard + request-time-boot patterns. This
  feature adopts them verbatim.
- **The wordpress/mcp-adapter package is the delivery mechanism**: When
  the operator saves a tool set, the `wordpress/mcp-adapter` package
  is responsible for exposing those abilities as MCP tools to connected
  clients. This feature does not itself implement the client-facing MCP
  protocol — it manages the per-server selection stored in the
  database AND wires a `mcp_adapter_pre_tool_call` filter callback that
  enforces the selection at each tool invocation (FR-029).
- **Enforcement is additive on top of F015 + F017**: F020's
  `mcp_adapter_pre_tool_call` callback runs at priority 30, after F015
  access control (priority 10) and F017 ability exposure (priority 20).
  It honors deny-precedence — an ability that F015 or F017 has already
  denied MUST remain denied; F020 cannot re-allow. This means F020
  restricts the visible surface *within* what F017 already allows;
  it does NOT bypass the abilities-tab exposure toggle. Spec's earlier
  claim that "presence in Tools is authoritative for MCP tool exposure"
  is refined by this stacking model: presence in Tools is *necessary*
  for exposure, but F015 + F017 remain *sufficient* deny gates.
  **List-time hiding** of unadded abilities from the MCP `tools/list`
  discovery endpoint is deferred to a follow-up feature; F020 ships
  call-time enforcement only. AI clients continue to see all F017-allowed
  abilities in `tools/list` but get 403 on call for any not in the F020
  tools set.
- **Presence-based storage is the correct model**: Because the shuttle-
  picker UX has only two states per ability (in the list / not in the
  list), the storage layer is presence-based (row exists / no row) — no
  `is_exposed` boolean column. A boolean would introduce a third state
  ("row exists but false") that the UI cannot represent.
- **Optimistic-per-toggle POST is the shipped workflow** *(pivot
  2026-07-09 post-implementation, superseding the earlier "Explicit Save
  / Cancel is the correct workflow" assumption)*: Each Add / Remove /
  Reset click POSTs immediately with local rollback on failure. This
  now matches Feature 017's DataViews-grid pattern in commit semantics,
  making the two features functionally equivalent in workflow. The
  only remaining divergence between F020 and F017 is the visual
  container (shuttle picker vs DataViews toggle grid) — see plan.md
  §Complexity Tracking for the surviving Principle IV deviation
  rationale. The originally-planned `DEC-TOOL-EDIT-EXPLICIT-BATCH-COMMIT`
  memory entry has been **withdrawn** from the T060 capture queue.
- **Feature 017 stays untouched**: The `MCPServerAbility` BerlinDB
  module, `AbilitiesController` REST controller, `AbilitiesTab`, and
  the entire `src/js/abilities.js` bundle receive zero edits. The two
  features are architecturally parallel but independent — no shared
  data model, no shared code paths.
- **Feature 019 tab filter contract stays untouched**: The
  `acrossai_mcp_manager_server_tabs` filter, the Registry class, and
  the priority slot map (Tools = 50) are unchanged. Third-party plugins
  filtering the tab list continue to work without modification.
- **No data migration**: The current Tools tab renders a static
  reference table with no persistent storage. The new BerlinDB table
  lands empty on activation. Every existing server starts with an empty
  tool set — this is the correct initial state (shown to the operator
  as the empty state + warning banner).
- **Concurrent editors are rare and safe**: The user population is
  site administrators, not general users. Last-writer-wins semantics
  are acceptable; no optimistic locking is implemented. If future work
  requires audit history, that lands in a separate feature.
- **Multisite scope**: The table is per-site (not global). Each site in
  a multisite network has its own `wp_acrossai_mcp_server_tools` table
  and its own selections.
