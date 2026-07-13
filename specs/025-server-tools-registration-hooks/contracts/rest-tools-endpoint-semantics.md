# Contract: REST endpoint semantics for `/wp-json/acrossai-mcp-manager/v1/servers/{id}/tools`

**Route pair**: `GET` + `POST` on the same URI.
**Auth**: `manage_options` on both, unchanged from Feature 020.
**Introduced**: Feature 020.
**Modified by**: Feature 025 (this contract).

## What DOESN'T change

- Route URI.
- HTTP methods.
- Auth (`permission_callback`).
- Response HTTP status codes on the golden path (200 on both).
- Response envelope shape (`{ tools: [...] }` on GET; `{ tools: [...], updated: bool, added: [...], removed: [...] }` on POST).
- Nonce handling.
- Third-party API consumer compatibility on the wire.

## What DOES change

### 1. POST accepts protocol slugs (and bypasses catalog validation for them)

**Before F025**: `ToolsController::EXCLUDED_SLUGS` (`mcp-adapter/discover-abilities`, `mcp-adapter/get-ability-info`, `mcp-adapter/execute-ability`) triggered a 400 response if any appeared in the request body's `tools` array.

**After F025**: any of the three protocol slugs is a valid entry. The controller routes protocol slugs to the row's `tool_*` columns via `ToolPolicy::split_payload()`; every other slug hits the F020 curated path via `MCPServerToolQuery::replace_set()`.

**Validation rule** (per FR-018): protocol slugs bypass `wp_get_abilities()` catalog validation entirely — their validity is guaranteed by `ToolPolicy::PROTOCOL_TOOLS`, which is the canonical plugin source. Non-protocol slugs are still catalog-validated as before; a payload containing any unknown non-protocol slug still returns 400 with the existing `acrossai_mcp_invalid_tool_slug` error.

**Why the bypass**: runtime evidence 2026-07-14 confirmed the vendored mcp-adapter's `wp_register_ability` calls for the three protocol slugs run too late to be visible to `wp_get_abilities()` on REST requests (see the GET §3 rationale below for the full bootstrap-timing story). Requiring catalog resolution would fail 400 on every legitimate protocol-slug POST — including the US3 Reset payload — even when the runtime is behaving correctly.

**Backwards compatibility**: existing REST clients that DID NOT send protocol slugs continue to work identically. Existing REST clients that DID send protocol slugs (and thus got a 400) now get a 200 — this is a strict widening of accepted input.

### 2. GET composes across both storage layers

**Before F025**: `GET` returned `MCPServerToolQuery::get_added_slugs()` verbatim — protocol slugs never appeared in the response because they were never in the DB.

**After F025**: `GET` returns `ToolPolicy::compose_for_row( $row )` — the union of enabled `tool_*` columns and curated slugs.

**Backwards compatibility**: existing REST clients see additional entries (the three protocol slugs) in the response. Clients that iterate the array continue to work. Clients that hard-code the expected count would break, but none should exist — the F020 GET always warned callers that the count varied per-server.

### 3. GET's `abilities` catalog now includes protocol slugs with a runtime-timing-safe fallback

When `include_abilities=1`, F020's response also returned an `abilities` array — the ability catalog with `EXCLUDED_SLUGS` silently filtered out. F025 removes that filter AND adds a runtime-timing-safe fallback so the three protocol slugs are **always** present in the catalog, regardless of whether the vendored mcp-adapter has registered them into `wp_get_abilities()` by REST-handler time.

**Composition rule** (per FR-018):

1. `ToolsController::get_tools()` iterates `wp_get_abilities()` and records the names it observes.
2. It then iterates `ToolPolicy::PROTOCOL_TOOL_METADATA` (three canonical entries: `Discover Abilities` / `Get Ability Info` / `Execute Ability`) and appends any protocol slug **not already seen** in step 1.
3. Result: `abilities` always contains the three protocol slugs. If `wp_get_abilities()` HAS them (edge case where the timing works), the vendor's authoritative entries win. If not, the `ToolPolicy` fallback ships.

**Rationale**: the vendored mcp-adapter registers the three protocol abilities via `wp_register_ability` on `wp_abilities_api_init` — but the vendor's listener attaches inside `Controller::initialize_adapter()` on `rest_api_init`, which fires AFTER `wp_abilities_api_init` on REST requests whose Abilities-API bootstrap already ran on `init`. Runtime evidence 2026-07-14 confirmed the catalog would otherwise be missing the three protocol slugs at POST/GET time, breaking left-pane re-add UX and third-party API contract expectations.

**Backwards compatibility**: existing catalog consumers see three additional entries with names `Discover Abilities`, `Get Ability Info`, `Execute Ability`. Their `type` is `'tool'`, `category` is `'mcp-adapter'`. Clients that filter by slug pattern (`starts_with('mcp-adapter/')`) can still exclude them.

## Request/Response shapes (post-F025)

### GET `/wp-json/acrossai-mcp-manager/v1/servers/{id}/tools`

**Query params**:
- `include_abilities: boolean` (default `false`) — when `true`, includes the `abilities` catalog.

**200 Response**:

```json
{
    "tools": [
        "mcp-adapter/discover-abilities",
        "mcp-adapter/get-ability-info",
        "mcp-adapter/execute-ability",
        "acrossai-abilities-manager/plugin-list",
        "acrossai-abilities-manager/plugin-install"
    ],
    "abilities": [
        {
            "name": "mcp-adapter/discover-abilities",
            "label": "Discover Abilities",
            "description": "Lists all publicly available WordPress abilities...",
            "type": "tool"
        },
        ...
    ]
}
```

Order stability: `tools` follows `ToolPolicy::compose_for_row()` — protocol slugs in `COLUMN_MAP` key order (only for columns where value is `1`), then curated slugs in row-insertion order.

### POST `/wp-json/acrossai-mcp-manager/v1/servers/{id}/tools`

**Request body**:

```json
{
    "tools": [
        "mcp-adapter/discover-abilities",
        "mcp-adapter/get-ability-info",
        "acrossai-abilities-manager/plugin-list"
    ]
}
```

Any mix of protocol and non-protocol slugs is accepted. Duplicates are collapsed. Empty string entries are stripped.

**200 Response**:

```json
{
    "tools": [
        "mcp-adapter/discover-abilities",
        "mcp-adapter/get-ability-info",
        "acrossai-abilities-manager/plugin-list"
    ],
    "updated": true,
    "added": [ "acrossai-abilities-manager/plugin-list" ],
    "removed": [ "mcp-adapter/execute-ability", "some-old-slug" ]
}
```

`added` / `removed` describe the diff APPLIED to the composed tool list. Column flips contribute to `added`/`removed` alongside curated-row deltas — a single unified diff view.

**4xx Responses** (unchanged from F020):
- `400 Bad Request` — `server_id` invalid, `tools` missing or not an array, or slug not a registered WordPress ability. (Note: F025 removes the specific 400-for-protocol-slug branch; protocol slugs are now valid.)
- `403 Forbidden` — capability check failed.
- `404 Not Found` — no server row for `{id}`.

## Internal handling on POST

Pseudocode:

```php
$normalized = /* sanitize + dedup $body['tools'] */;
$split      = ToolPolicy::split_payload( $normalized );

$prior_columns = extract_columns_from( MCPServerQuery::get_item( $server_id ) );
$prior_curated = MCPServerToolQuery::instance()->get_added_slugs( $server_id );

// Write layer 1: columns.
MCPServerQuery::instance()->update_item( $server_id, $split['columns'] );

// Write layer 2: curated rows (transactional, returns diff).
$curated_diff = MCPServerToolQuery::instance()->replace_set( $server_id, $split['curated'] );

// Emit acrossai_mcp_tools_changed for column flips.
foreach ( ToolPolicy::COLUMN_MAP as $column => $slug ) {
    if ( $prior_columns[$column] !== $split['columns'][$column] ) {
        $op = ( 1 === $split['columns'][$column] ) ? 'added' : 'removed';
        do_action( 'acrossai_mcp_tools_changed', [
            'server_id'    => $server_id,
            'ability_slug' => $slug,
            'operation'    => $op,
        ] );
    }
}
// (Curated flips continue firing per F020's replace_set() existing loop.)

// Flush F020's per-request cache in ToolExposureGate.
ToolExposureGate::flush_cache_for_server( $server_id );

// Compute unified diff for response.
$added   = array_merge( columns_diff_added( $prior_columns, $split['columns'] ), $curated_diff['added'] );
$removed = array_merge( columns_diff_removed( $prior_columns, $split['columns'] ), $curated_diff['removed'] );

return rest_response( [
    'tools'   => ToolPolicy::compose_for_row( MCPServerQuery::get_item( $server_id ) ),
    'updated' => ! empty( $added ) || ! empty( $removed ),
    'added'   => array_values( $added ),
    'removed' => array_values( $removed ),
] );
```

The two writes are NOT wrapped in an outer transaction — see [data-model.md](../data-model.md) §"Two-write POST path — accepted race" for the risk analysis.

## Interaction with call-time enforcement

F020's `ToolExposureGate` on `mcp_adapter_pre_tool_call` priority 30 is unchanged. Its `EXCLUDED_SLUGS` bypass remains in place (vestigial safety net for cached AI clients), but the adapter itself will refuse to dispatch an unregistered tool — a Remove that flips a column to `0` truly hides the tool at both `tools/list` (registration side) and `tools/call` (adapter side).
