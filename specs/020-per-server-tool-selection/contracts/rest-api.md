# REST API Contract — Feature 020

**Namespace**: `acrossai-mcp-manager/v1` (same as F017)
**Controller**: `AcrossAI_MCP_Manager\Includes\REST\ToolsController` (singleton, no hooks in ctor per A1)
**Registration**: `Main::define_admin_hooks()` wires
`$this->loader->add_action( 'rest_api_init', ToolsController::instance(), 'register_routes' );`
immediately after F017's `$abilities_rest = ...` block.

Two routes under the base path `/servers/(?P<server_id>\d+)/tools`.

---

## Route 1 — `GET /servers/{server_id}/tools`

**Purpose**: Return the current tool slug set for `server_id`. Optionally include the full ability catalog for cold-start rendering.

### Request

| Path param  | Type     | Validation       |
|-------------|----------|------------------|
| `server_id` | integer  | `absint()`; must match an existing row in `wp_acrossai_mcp_servers` |

| Query param          | Type    | Default | Description                                                       |
|----------------------|---------|---------|-------------------------------------------------------------------|
| `include_abilities`  | boolean | `false` | If `1` / `true`, include the full ability catalog (excluding the 3 protocol tools) in the response `abilities` field. |

**Auth**: `permission_callback` requires `current_user_can( 'manage_options' )`. `X-WP-Nonce` header enforced by REST middleware (WordPress core).

### Response — 200 OK

```json
{
  "tools": [
    "acrossai-core-abilities/create-post",
    "acrossai-core-abilities/approve-comment"
  ],
  "abilities": [
    {
      "name": "acrossai-core-abilities/create-post",
      "label": "Create Post",
      "description": "Creates a new post with title, content, status, and taxonomy terms.",
      "type": "Tool",
      "category": "content"
    }
  ]
}
```

- `tools`: array of ability slug strings. Order not guaranteed. Empty array when no tools are added.
- `abilities`: **present only when** `include_abilities` is truthy in the query string. The three excluded MCP-adapter protocol slugs (`mcp-adapter/discover-abilities`, `mcp-adapter/get-ability-info`, `mcp-adapter/execute-ability`) are filtered out server-side.
- `type` — one of `"Tool"`, `"Prompt"`, `"Resource"`, `""` (empty string when the ability's meta doesn't declare a type).
- `category` — free-form string, empty when unset.

### Response — 403 Forbidden

Caller lacks `manage_options`. Standard WP REST error body.

### Response — 404 Not Found

`server_id` does not resolve to an existing row in `wp_acrossai_mcp_servers`.

```json
{
  "code": "acrossai_mcp_server_not_found",
  "message": "MCP server not found.",
  "data": { "status": 404, "server_id": 42 }
}
```

---

## Route 2 — `POST /servers/{server_id}/tools`

**Purpose**: Replace the tool slug set for `server_id` with the submitted set. Server-side diff, all-or-nothing validation, no partial writes.

### Request

| Path param  | Type    | Validation       |
|-------------|---------|------------------|
| `server_id` | integer | `absint()`; must match an existing row in `wp_acrossai_mcp_servers` |

**Body** (JSON):

```json
{
  "tools": [
    "acrossai-core-abilities/create-post",
    "acrossai-core-abilities/approve-comment"
  ]
}
```

- `tools` — array of ability slug strings. Duplicates within the array collapse to one. Empty array is valid — means "clear all tools for this server". Missing/null/non-array field returns 400.

**Explicit `args` schema** (SEC-020-005 + SEC-020-009 remediation — passed to `register_rest_route`; covers both the path param and the body param):

```php
'args' => array(
    'server_id' => array(
        'type'              => 'integer',
        'required'          => true,
        'sanitize_callback' => 'absint',
        'validate_callback' => static function ( $value ) {
            return $value > 0 || new \WP_Error(
                'rest_invalid_id',
                esc_html__( 'server_id must be a positive integer.', 'acrossai-mcp-manager' ),
                array( 'status' => 400 )
            );
        },
    ),
    'tools' => array(
        'type'              => 'array',
        'items'             => array( 'type' => 'string' ),
        'required'          => true,
        'sanitize_callback' => static function ( $value ) {
            return array_values( array_map( 'sanitize_text_field', (array) $value ) );
        },
        'validate_callback' => static function ( $value ) {
            if ( ! is_array( $value ) ) {
                return new \WP_Error(
                    'rest_invalid_type',
                    esc_html__( 'The `tools` field must be an array of ability slug strings.', 'acrossai-mcp-manager' ),
                    array( 'status' => 400 )
                );
            }
            return true;
        },
    ),
),
```

Route 1 (`GET`) reuses the same `server_id` schema entry via the shared `args` block. `tools` is body-only and does not apply to GET.

Guarantees WordPress REST middleware rejects malformed bodies (missing field, wrong type, non-array) AND malformed path params (server_id=0, negative, non-numeric) BEFORE the controller callback executes. Every slug is sanitized at the boundary; the controller receives a normalized array of strings and a positive integer server_id.

**Auth**: `permission_callback` requires `current_user_can( 'manage_options' )`. `X-WP-Nonce` header enforced by REST middleware.

### Validation (fires before any DB write)

Every submitted slug is sanitized via `sanitize_text_field()` and validated against `wp_get_abilities()` when the function exists.

- Slug not present in the catalog → REJECT WHOLE BATCH. No partial writes.
- Slug present in `EXCLUDED_SLUGS` (the three protocol tools) → REJECT WHOLE BATCH (defense-in-depth — the UI already filters them out, but the API is public).
- Empty string, non-string type → drop from the set (normalization step); doesn't fail the request.

### Response — 200 OK

```json
{
  "tools": [
    "acrossai-core-abilities/create-post",
    "acrossai-core-abilities/approve-comment"
  ]
}
```

`tools` reflects the post-save DB truth. Response = fresh read after the diff apply. Client uses this to reconcile `added` state without a follow-up GET (matches F017 FR-010 pattern).

### Response — 400 Bad Request (invalid slug)

```json
{
  "code": "acrossai_mcp_invalid_tool_slug",
  "message": "One or more submitted ability slugs are not registered on this site.",
  "data": {
    "status": 400,
    "invalid_slugs": ["typoed-slug", "another/bad-one"]
  }
}
```

The DB is unchanged when this response is returned.

### Response — 400 Bad Request (excluded slug)

```json
{
  "code": "acrossai_mcp_excluded_tool_slug",
  "message": "Cannot add MCP-adapter protocol tools as per-server tools.",
  "data": {
    "status": 400,
    "excluded_slugs": ["mcp-adapter/discover-abilities"]
  }
}
```

The DB is unchanged.

### Response — 403 Forbidden

Caller lacks `manage_options`.

### Response — 404 Not Found

`server_id` does not resolve.

### Response — 500 Internal Server Error (transaction rollback)

Returned when `Query::replace_set()` rolls back its transaction (`ROLLBACK`) due to a lock-wait timeout, deadlock, or downstream exception. The DB is left in its pre-call state — no partial writes reach the table.

```json
{
  "code": "acrossai_mcp_tools_save_failed",
  "message": "Could not save the tools list. Please try again.",
  "data": { "status": 500 }
}
```

**Body composition contract** (SEC-020-010 remediation):

- `code` MUST be the literal `acrossai_mcp_tools_save_failed`. Stable public API; consumers can match on it.
- `message` MUST be the human-readable generic string above. MUST NOT contain the underlying exception message, MySQL error text, table names, column names, or any other schema hint.
- `data.status` MUST be `500`.
- The underlying `\Throwable::getMessage()` MUST be `error_log`'d server-side with context (server_id, count of desired slugs, but NOT the slugs themselves to keep log volume bounded). Format:
  ```
  [acrossai_mcp_tools_save_failed] server_id={id}, desired_count={n}: {exception message}
  ```

Rationale: DB error text (from a `\wpdb::last_error` bubble-up) can leak table names or column names to the caller, revealing schema information to a client that shouldn't have it. Standard WP REST convention: log-specific / respond-generic.

### Side effects on 200

For each slug in the `added` diff subset, the controller fires the action inside a `try/catch` (FR-031):

```php
foreach ( $applied['added'] as $slug ) {
    try {
        do_action( 'acrossai_mcp_tools_changed', array(
            'server_id'    => (int) $server_id,
            'ability_slug' => (string) $slug,
            'operation'    => 'added',
        ) );
    } catch ( \Throwable $e ) {
        error_log( sprintf(
            '[acrossai_mcp_tools_changed] observer error for %s on %d: %s',
            $slug, $server_id, $e->getMessage()
        ) );
    }
}
```

The identical loop runs for `$applied['removed']` with `'operation' => 'removed'`.

**Isolation invariant** (SEC-020-004 remediation):

- The DB write commits via `Query::replace_set()` BEFORE the observer loop begins. A broken observer NEVER rolls back a successful save.
- Each observer fires INDIVIDUALLY inside a `try/catch`. One thrown observer does not prevent later observers from firing.
- Caught exceptions are `error_log`'d but NEVER surface to the REST response. HTTP 200 is guaranteed once the DB commit succeeds, regardless of observer health.

Idempotent saves (desired set equals stored set) fire zero actions. Each action fires exactly once per applied change per POST request.

---

## Security Notes

- **S1 (nonce)** — enforced by WordPress core REST middleware via `X-WP-Nonce` header. Client uses `@wordpress/api-fetch` nonce middleware seeded from `wp_create_nonce( 'wp_rest' )` in the localize payload.
- **S2 (permission_callback)** — explicit `manage_options` check on both routes; NO `__return_true`.
- **S4 (`$wpdb->prepare()`)** — all writes go through BerlinDB's inherited prepared-statement layer.
- **B7 (mass-assignment)** — `Query::replace_set()` takes a normalized slug array only; no `$wpdb->update( $_POST )` pattern. The controller filters against `wp_get_abilities()` before calling `replace_set()`.
- **B17 (`rest_url()` trailing slash)** — client localize wraps with `untrailingslashit( rest_url() )`.
- **Server-id boundary** — every route callback checks `MCPServerQuery::instance()->get_item( $server_id )` and returns 404 if false. Prevents cross-server data disclosure.

---

## Requirements Traceability

| REST behavior                          | Requirement |
|----------------------------------------|-------------|
| GET returns current tools              | FR-002, FR-014 |
| GET include_abilities passthrough      | Data-model §WP_Ability |
| POST replace-all semantics             | FR-010 |
| POST invalid-slug rejection all-or-nothing | FR-022 |
| POST fires `acrossai_mcp_tools_changed` per change | FR-023 |
| `manage_options` on both routes        | FR-021, S2 |
| 404 on unknown server_id               | Security notes |
| Excluded slugs never accepted          | FR-025 |
