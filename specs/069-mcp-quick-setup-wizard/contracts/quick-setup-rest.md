# Contract: `/acrossai-mcp-manager/v1/quick-setup/*` REST routes

**Namespace**: `acrossai-mcp-manager/v1`
**Controller**: `\AcrossAI_MCP_Manager\Includes\REST\QuickSetupController` (singleton)
**Registration**: on `rest_api_init` via `Main::define_public_hooks()`

All routes:
- `permission_callback` returns boolean derived from `current_user_can( 'manage_options' )` (S2)
- Every mutating (`POST`) route additionally requires a valid `X-WP-Nonce` header for action `wp_rest` (S1)
- All request/response bodies are `application/json`

---

## Route 1 — `GET /quick-setup/state`

Returns the full snapshot the React app needs to render every step.

### Request

```
GET /wp-json/acrossai-mcp-manager/v1/quick-setup/state
X-WP-Nonce: <wp_rest nonce>
```

### Response — 200 OK

```json
{
  "servers": [
    {
      "id": 1,
      "name": "Default MCP Server",
      "slug": "mcp-adapter-default-server",
      "route_namespace": "mcp",
      "route": "mcp-adapter-default-server",
      "route_full": "mcp/mcp-adapter-default-server",
      "enabled": true
    }
  ],
  "abilities": {
    "total": 3,
    "hasManagerPlugin": false
  },
  "plugins": {
    "acrossaiPro": "missing",
    "abilitiesManager": "missing"
  },
  "wizardState": {
    "current_step": 1,
    "server_id": null,
    "access_saved": false,
    "abilities_saved": false,
    "enabled": false,
    "method": null,
    "created_at": 1755388800
  }
}
```

### Response fields

| Field | Type | Source |
|---|---|---|
| `servers[]` | array of `MCPServer` | `MCPServerQuery::instance()->query([ 'orderby' => 'id', 'order' => 'ASC' ])` |
| `abilities.total` | int | `count( wp_get_abilities() )` |
| `abilities.hasManagerPlugin` | bool | mirror of `plugins.abilitiesManager === 'active'` |
| `plugins.acrossaiPro` | `'missing' \| 'inactive' \| 'active'` | `is_plugin_active('acrossai-pro/acrossai-pro.php')` — matches F040 tri-state semantics |
| `plugins.abilitiesManager` | `'missing' \| 'inactive' \| 'active'` | `is_plugin_active('acrossai-abilities-manager/acrossai-abilities-manager.php')` |
| `wizardState.*` | see `data-model.md` E1 | `get_transient('acrossai_mcp_manager_quick_setup_state_{user_id}')` OR defaults |

### Error responses

| Status | Body | Trigger |
|---|---|---|
| 401 | `{"code":"rest_forbidden", "message":"…"}` | `permission_callback` returned false |

---

## Route 2 — `POST /quick-setup/step`

Persists per-step scratchpad state AND delegates authoritative writes to existing APIs (server create, server enable, abilities enable-all).

### Request

```
POST /wp-json/acrossai-mcp-manager/v1/quick-setup/step
X-WP-Nonce: <wp_rest nonce>
Content-Type: application/json

{
  "step": 1|2|3|4|5,
  "data": { ...step-specific payload... }
}
```

### Per-step `data` payloads

**Step 1a — select existing server**
```json
{ "step": 1, "data": { "server_id": 42 } }
```
- Server MUST exist in `wp_acrossai_mcp_servers` — else 410 Gone.
- Writes: scratchpad update only.

**Step 1b — create new server**
```json
{
  "step": 1,
  "data": {
    "new_server": {
      "server_name":            "My New Server",
      "server_slug":            "my-new-server",
      "description":            "Optional description",
      "server_route_namespace": "mcp",
      "server_route":           "my-new-server",
      "server_version":         "v1.0.0"
    }
  }
}
```
- Sanitization: `server_name` → `sanitize_text_field`; `server_slug`/`server_route` → `sanitize_title`; `description` → `sanitize_textarea_field`; `server_route_namespace`/`server_version` → `sanitize_key`/`sanitize_text_field`.
- Writes: `MCPServerQuery::instance()->add_item($data)` → scratchpad populates with new `server_id`.
- Response includes refreshed `servers[]` list.

**Step 2 — access rule saved marker**
```json
{ "step": 2, "data": { "access_saved": true } }
```
- Authoritative AC write is performed by the embedded `<AccessControlEditor>` component hitting wpb-ac REST directly. This step-save only marks the wizard scratchpad flag.

**Step 3 — enable all abilities**
```json
{ "step": 3, "data": { "enable_all_abilities": true } }
```
- Server-side effect: if `state.plugins.abilitiesManager === 'active'`, controller POSTs to F017's abilities-update route with the full ability set for `state.wizardState.server_id`. If Abilities Manager is inactive, this is a no-op (scratchpad flag set, no F017 call).

**Step 4 — enable server**
```json
{ "step": 4, "data": { "enabled": true } }
```
- Writes: `MCPServerQuery::instance()->update_item($server_id, [ 'is_enabled' => 1 ])`.
- Scratchpad `enabled` flag mirrors DB.

**Step 5 — pick method**
```json
{ "step": 5, "data": { "method": "connectors" | "client" | "npm" | "wpcli" } }
```
- Scratchpad only; no DB side effect.

### Response — 200 OK

Every response includes the refreshed wizard state (and optionally refreshed lookup data):

```json
{
  "wizardState": { ... },
  "servers": [ ... ]   // included when a server was created (step 1b) or enabled (step 4)
}
```

### Error responses

| Status | Body | Trigger |
|---|---|---|
| 400 | `{"code":"acrossai_mcp_quick_setup_invalid_step"}` | `step` not in `1..5` |
| 400 | `{"code":"acrossai_mcp_quick_setup_invalid_data"}` | payload shape doesn't match step |
| 401 | `{"code":"rest_forbidden"}` | permission_callback false |
| 403 | `{"code":"rest_cookie_invalid_nonce"}` | missing/invalid X-WP-Nonce |
| 410 | `{"code":"acrossai_mcp_quick_setup_server_gone"}` | referenced server_id no longer exists |
| 500 | `{"code":"acrossai_mcp_quick_setup_persist_failed"}` | scratchpad transient set failed OR authoritative API write failed |

---

## Route 3 — `POST /quick-setup/complete`

Marks the wizard complete: deletes the per-user scratchpad. (The activation-redirect transient is already consumed at the previous admin_init cycle — nothing to clear here.)

### Request

```
POST /wp-json/acrossai-mcp-manager/v1/quick-setup/complete
X-WP-Nonce: <wp_rest nonce>
```

Empty body.

### Response — 204 No Content

No body. React app on success navigates to `?step=done` (completion screen) or `?page=acrossai_mcp_manager&action=edit&server={id}` (Go to server dashboard CTA).

### Error responses

| Status | Body | Trigger |
|---|---|---|
| 401 | `{"code":"rest_forbidden"}` | permission_callback false |
| 403 | `{"code":"rest_cookie_invalid_nonce"}` | missing/invalid X-WP-Nonce |

---

## Registration snippet (for reference)

```php
// includes/Main.php :: define_public_hooks()
$quick_setup_rest = \AcrossAI_MCP_Manager\Includes\REST\QuickSetupController::instance();
$this->loader->add_action( 'rest_api_init', $quick_setup_rest, 'register_routes' );
```

```php
// includes/REST/QuickSetupController.php :: register_routes()
register_rest_route(
    'acrossai-mcp-manager/v1',
    '/quick-setup/state',
    array(
        'methods'             => 'GET',
        'callback'            => array( $this, 'handle_state' ),
        'permission_callback' => static function () {
            return current_user_can( 'manage_options' );
        },
    )
);
// ... same shape for POST /quick-setup/step + POST /quick-setup/complete
```

---

## Test coverage (target)

Add `tests/phpunit/REST/QuickSetupControllerTest.php` — every route × every documented status code = **≥15 test methods**:

- `GET /state`: 401 (subscriber) + 200 (admin, empty scratchpad) + 200 (admin, populated scratchpad) + 200 (admin, plugin states vary)
- `POST /step`: 401 + 403 (bad nonce) + 400 (invalid step) + 400 (missing data) + 200 (each of the 5 valid step shapes) + 410 (server_id gone) + 500 (mocked persist failure)
- `POST /complete`: 401 + 403 + 204 (happy path) + 204 idempotent (called twice)
