# Data Model — Phase 2: Admin UI Migration

**Date**: 2026-06-17 | **Branch**: `002-admin-ui`

This phase reads and writes four data shapes. **No new tables, no new
columns** are created in this phase — Phase 2 only changes the *access
path* (BerlinDB Query instead of static helper).

---

## E1. MCP Server row

**Table**: `{wpdb->prefix}acrossai_mcp_servers`
**Owned by**: `Includes\Database\MCPServer\Table` (prerequisite phase)
**Accessed by**: `Includes\Database\MCPServer\Query` (this phase reads + writes)
**Surfaced in admin**: list table (US2) + four-tab edit page (US3) + create form (US2.7/2.8)

### Fields (carried over from source `src/Database/MCPServerTable.php`)

| Field | Type | Notes |
|---|---|---|
| `id` | bigint(20) unsigned | Primary key, auto-increment |
| `name` | varchar(191) | Display name; sanitised with `sanitize_text_field()` on write (FR-009) |
| `slug` | varchar(191) | Unique; collision check on create (FR-007a). **TASK-SEC-003 follow-up**: currently sanitised with `sanitize_text_field()` per source 1:1 port; consider tightening to `sanitize_key()` in a later phase (see security-review-plan.md SEC-003). |
| `description` | text | `sanitize_textarea_field()` on write |
| `status` | enum('enabled', 'disabled') | Toggled by US2.2; default 'enabled' on create |
| `registered_from` | varchar(64) | Source attribution (`admin`, `code`, `cli`); rendered read-only in list |
| `route_namespace` | varchar(191) | `sanitize_text_field()` — no leading slash by convention |
| `route` | varchar(191) | `sanitize_text_field()` — leading slash preserved |
| `version` | varchar(32) | `sanitize_text_field()` |
| `claude_oauth_client_id` | varchar(191) | `sanitize_text_field()` (FR-012) |
| `claude_oauth_client_secret` | varchar(191) | `sanitize_text_field()`; masked on re-render. **TASK-SEC-001 follow-up**: stored plaintext at rest per source 1:1 port; consider wrapping with `WP_SECURE_AUTH_KEY`-derived AES-GCM in Phase 6 (Claude Connectors) and extending Constitution §III bullet 7 to cover outbound client secrets (see security-review-plan.md SEC-001). |
| `claude_oauth_redirect_uri` | text | `esc_url_raw()` on write, `esc_url()` on read |
| `date_created` | datetime | Auto-set by BerlinDB |
| `date_modified` | datetime | Auto-set by BerlinDB |

### Validation rules

- `slug` is unique across the table → FR-007a slug-exists check before insert
- `status` MUST be exactly `'enabled'` or `'disabled'` (no third state)
- `route_namespace` and `route` MUST be non-empty strings — General-tab
  save (FR-009) blocks empty submissions and surfaces an error notice

### State transitions

```text
(none) --create--> enabled --toggle--> disabled --toggle--> enabled
                       \                    \
                        delete              delete
                          \                   \
                          (gone)             (gone)
```

No third state, no soft delete (hard delete only — FR-006).

---

## E2. CLI Auth Log entry

**Table**: `{wpdb->prefix}acrossai_mcp_cli_auth_log`
**Owned by**: `Includes\Database\CliAuthLog\Table` (prerequisite phase)
**Accessed by**: `Includes\Database\CliAuthLog\Query` (this phase reads only)
**Surfaced in admin**: CLI Auth Log submenu list table

### Fields (carried over from source)

| Field | Type | Notes |
|---|---|---|
| `id` | bigint(20) unsigned | PK auto-increment |
| `user_login` | varchar(60) | The WP user who attempted the auth |
| `request_ip` | varchar(45) | IPv4/IPv6 |
| `request_user_agent` | text | Capped at 1024 chars when rendered |
| `result` | enum('success', 'failure') | Read-only in the admin table |
| `failure_reason` | varchar(191) | Empty for success rows |
| `created_at` | datetime | Sort key (DESC) for the list view |

### Validation rules

This phase is read-only on this table — no validation is added by Phase 2.

### State transitions

None (immutable log table).

---

## E3. Application Password (per server)

**Owned by**: WordPress core (application passwords are stored as user
meta on a synthetic per-server user account, per the source repo's
existing pattern).

**Accessed by**: `Admin\Partials\ApplicationPasswords` (this phase ports
this class 1:1 — no contract changes).

**Surfaced in admin**: Tokens tab on the four-tab edit page (US3.3).

This phase does NOT change the application-password data shape. It only:

1. Moves the class into `admin/Partials/` namespace.
2. Removes any `add_action`/`add_filter` from its constructor.
3. Wires its hooks through the Loader in `Includes\Main::define_admin_hooks()`.

**Security invariant preserved**: Application Password hashes are stored
via WordPress core (`WP_Application_Passwords::create_new_application_password()`),
which writes only the hash. The plaintext is returned to the caller once
and never persisted by this plugin (Constitution §III bullet 7).

---

## E4. Adapter-missing notice dismissal flag

**Storage**: WordPress user meta.
**Key**: `acrossai_mcp_dismissed_adapter_notice`
**Value**: `1` (presence-as-flag; absence = not yet dismissed)
**Lifetime**: Sticky — never reset on plugin upgrade (Q3 clarification 2026-06-17).
**Cleared by**: Nothing in this phase. (Future plugin uninstall would also drop user meta keyed to this plugin if a cleanup routine is added later — out of scope here.)

### Write path

1. User clicks the X on the rendered notice.
2. Browser fires `POST admin-ajax.php?action=acrossai_mcp_dismiss_adapter_notice`
   with `_ajax_nonce`.
3. Server: `check_ajax_referer( 'acrossai_mcp_dismiss_adapter_notice' )` +
   `current_user_can( 'manage_options' )` (both required).
4. Server: `update_user_meta( get_current_user_id(),
   'acrossai_mcp_dismissed_adapter_notice', 1 )`.
5. Server: `wp_send_json_success()`.

### Read path

`Admin\Partials\Settings::render_missing_adapter_notice()` short-circuits
when EITHER `class_exists( '\WP\MCP\Plugin' )` returns true OR the current
user's `acrossai_mcp_dismissed_adapter_notice` meta value is truthy.
