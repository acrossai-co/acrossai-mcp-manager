# REST API Contract — Feature 017

**Namespace**: `acrossai-mcp-manager/v1` (unchanged from existing plugin routes)
**Base**: `/wp-json/acrossai-mcp-manager/v1`

**Post-implement (Session 2026-07-08)** — the response shape shipped smaller than the initial contract described. The client sources ability metadata from `@wordpress/abilities` (`wp.data.select('core/abilities')`) and only asks this endpoint for the per-server override rows. See FR-009, FR-014, FR-035 for the current shipping contract.

## Route 1 — GET /servers/{server_id}/abilities

Return the per-server override rows. Optionally include the full ability list as a fallback (see `include_abilities` below).

**Path**: `GET /servers/(?P<server_id>\d+)/abilities`

### Auth

- `permission_callback`: `current_user_can( 'manage_options' )` (FR-012, S2). Never `__return_true`.
- Nonce: standard `X-WP-Nonce` header (validated by WP core).

### Path parameters

| Name | Type | Required | Sanitize | Notes |
|---|---|---|---|---|
| `server_id` | integer | yes | `absint()` | Must reference an existing `wp_acrossai_mcp_servers.id`. |

### Query parameters

| Name | Type | Default | Notes |
|---|---|---|---|
| `include_abilities` | boolean | `false` | Client-store fallback path (FR-035). When `true`, the response additionally includes an `abilities` array containing the full ability list from PHP `wp_get_abilities()`. |

### Request body

None.

### Success — 200 OK

**Content-Type**: `application/json`

Default (client uses `@wordpress/abilities` store for the ability list):

```json
{
  "overrides": [
    { "slug": "core/get-user-info",  "is_exposed": true  },
    { "slug": "ai/get-post-details", "is_exposed": false }
  ]
}
```

With `?include_abilities=1` (fallback path):

```json
{
  "overrides": [
    { "slug": "core/get-user-info", "is_exposed": true }
  ],
  "abilities": [
    {
      "name":        "core/get-user-info",
      "label":       "Get User Information",
      "category":    "user",
      "description": "Returns basic profile details ...",
      "meta":        { "mcp": { "public": true, "type": "tool" } }
    }
  ]
}
```

- `overrides[].slug` — ability name (unique per site).
- `overrides[].is_exposed` — the operator's explicit per-server value. Rows without an override do NOT appear in this array — the client falls back to the ability's own `meta[mcp][public]` for absent slugs (FR-007).
- `abilities[]` (fallback only) — matches the `@wordpress/abilities` store shape so the client's merge code is identical across paths.
- Orphan overrides (rows whose `slug` is not currently a registered ability) are preserved in the DB and included in `overrides[]` — the client-side merge naturally filters them out because no matching ability entry exists.
- **No PHP row filter** — the `acrossai_mcp_ability_row` filter that appeared in early drafts (FR-027) was retired Session 2026-07-08. Extension surface is JS-only.

### Errors

| Status | Code | When |
|---|---|---|
| `403` | `rest_forbidden` | Caller is unauthenticated or lacks `manage_options`. |
| `404` | `acrossai_mcp_server_not_found` | `server_id` does not match any row in `wp_acrossai_mcp_servers`. |

---

## Route 2 — POST /servers/{server_id}/abilities

Upsert a batch of `{ slug, is_exposed }` pairs for the given server and return the refreshed merged list.

**Path**: `POST /servers/(?P<server_id>\d+)/abilities`

### Auth

- `permission_callback`: `current_user_can( 'manage_options' )` (FR-012, S2). Never `__return_true`.
- Nonce: standard `X-WP-Nonce` header.

### Path parameters

Same as Route 1.

### Request body — application/json

```json
{
  "abilities": [
    { "slug": "core/get-user-info",  "is_exposed": true  },
    { "slug": "ai/get-post-details", "is_exposed": false }
  ]
}
```

- `abilities` — non-empty array.
- `abilities[].slug` — sanitized via `sanitize_text_field()`. MUST be present in `wp_get_abilities()` output at the time of the write. Unknown slugs cause the whole batch to be rejected with 400 — zero rows written (FR-011).
- `abilities[].is_exposed` — cast to `(bool)` before persist.

### Success — 200 OK

Same shape as GET **without** `?include_abilities=1` — a fresh `{ overrides: [ { slug, is_exposed } ] }`. No follow-up GET is required (FR-010). If the client already has the ability list from the `@wordpress/abilities` store, the refreshed overrides array is enough to re-render.

### Side effects

- For each `{ slug, is_exposed }` pair, the controller calls `Query::upsert( $server_id, $slug, $is_exposed )`.
- For each pair whose **effective** exposure value changed (resolver-before vs resolver-after), the controller fires:
  ```php
  do_action(
      'acrossai_mcp_ability_exposure_changed',
      (int)    $server_id,
      (string) $ability_slug,
      (bool)   $was,
      (bool)   $now,
      (int)    get_current_user_id()
  );
  ```
  Writes that leave the effective value unchanged do NOT fire the action (FR-024).

### Errors

| Status | Code | When |
|---|---|---|
| `400` | `acrossai_mcp_invalid_payload` | `abilities` is missing / not an array / empty / contains an entry lacking `slug` or `is_exposed`, OR any `slug` in the batch is not currently registered. Whole batch rejected. |
| `403` | `rest_forbidden` | Caller is unauthenticated or lacks `manage_options`. |
| `404` | `acrossai_mcp_server_not_found` | `server_id` does not match any row in `wp_acrossai_mcp_servers`. |

---

## PHP Row Filter — RETIRED (Session 2026-07-08)

Originally the READ handler ran each row through a PHP filter `acrossai_mcp_ability_row` so extensions could add server-side keys. That filter was retired during the `@wordpress/abilities` refactor because the server no longer merges ability metadata into rows — `AbilitiesController::get_abilities()` returns only the raw override rows. See `spec.md` FR-027 (marked RETIRED).

**Extension surface for adding per-row data**: JS-only. Companion plugins use `acrossaiMcpManager.abilities.row` (see `contracts/js-hooks.md` and `docs/abilities-tab-js-filters.md`).

## PHP Action (audit-adjacent hook)

**Action name**: `acrossai_mcp_ability_exposure_changed`
**Fired by**: `AbilitiesController::post_abilities()` — after `Query::upsert()` returns and iff the resolver's effective value changed.
**Signature**:

```php
do_action(
    'acrossai_mcp_ability_exposure_changed',
    int    $server_id,
    string $ability_slug,
    bool   $was,
    bool   $now,
    int    $user_id
);
```

**Semantics**:
- Fires exactly once per pair whose effective value changed.
- Return value is ignored (fire-and-forget observability — D19 precedent).
- Feature 017 does NOT ship a built-in subscriber. Operators may hook it to `error_log`, custom tables, Slack, SIEM, etc.
- Stability marker: `@since 0.0.10 @experimental May change without notice before 1.0.0`.

---

## Frozen Names (Contract)

Once this feature merges, the following identifiers become part of the plugin's public contract with companion plugins. Renaming them requires a deprecation cycle documented in `docs/extending-abilities-tab.md`:

- Namespace: `acrossai-mcp-manager/v1`
- Routes: `GET|POST /servers/{server_id}/abilities`
- Error codes: `acrossai_mcp_server_not_found`, `acrossai_mcp_invalid_payload`
- PHP filter: `acrossai_mcp_ability_row` — RETIRED (Session 2026-07-08); no PHP filter surface remains
- PHP action: `acrossai_mcp_ability_exposure_changed`
- Override row keys: `slug`, `is_exposed`
- Fallback `abilities[]` row keys (post-Session-2026-07-08): `name`, `label`, `category`, `description`, `meta` — mirrors the `@wordpress/abilities` store shape
