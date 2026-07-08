# JS Hooks & PHP Action Contract — Feature 020

Feature 020's public extensibility surface is deliberately small: three JavaScript filters via `@wordpress/hooks` plus one PHP action fired by the REST controller. Companion plugins that want to decorate the Tools tab or observe tool-set changes use these — no direct file imports, no monkey-patching.

Mirrors F017's pattern (`acrossaiMcpManager.abilities.{fields,actions,row}` + `acrossai_mcp_ability_exposure_changed`) but with the `tools` namespace and a different action name reflecting the different semantics.

All three JS filters are wrapped in a `safeApplyFilters()` boundary — a callback that throws is logged (via `console.error`) and the previous known-good value is returned; the mount never white-screens. Mirrors F017 exactly (`tests/jest/abilities/safeApplyFilters.test.js` covers the boundary — F020 has a thin wrapper test).

---

## JS Filter 1 — `acrossaiMcpManager.tools.fields`

**Purpose**: Decorate the row data shape rendered in the picker's two columns. Third-party plugins can add computed properties, transform labels, or annotate the badge.

**Fires**: Once per mount, immediately after `abilities` array is retrieved (either from the `core/abilities` runtime store or REST fallback).

**Signature**:

```javascript
applyFilters(
    'acrossaiMcpManager.tools.fields',
    fields,       // array<{ name, label, description, type, category }>
    context       // { serverId, serverSlug }
)
```

**Contract**:

- Callback receives the sanitized fields array and returns a possibly-modified array.
- Callback MUST return an array with the same length or shorter; adding rows is not supported (they wouldn't correspond to registered abilities and would fail server-side validation on Save).
- Callback MUST preserve the `name` field on each row — it's the primary key used for Add/Remove logic. Mutating `name` will make the row un-Addable.
- Callback MAY replace `label`, `description`, `type`, `category` with any string; empty strings are valid.
- Safe callback failure: throws → console.error → return original `fields` unchanged.

**Example**:

```javascript
addFilter( 'acrossaiMcpManager.tools.fields', 'my-plugin/annotate-danger', ( fields ) => {
    return fields.map( ( f ) => (
        f.name.includes( 'delete' )
            ? { ...f, label: f.label + ' ⚠' }
            : f
    ) );
} );
```

---

## JS Filter 2 — `acrossaiMcpManager.tools.actions`

**Purpose**: Inject additional header buttons in the picker's two column headers (beside "Add all →" / "Remove all"). Third-party plugins can add "Add all safe tools", "Import from template", etc.

**Fires**: Once per render pass (memoized), before the header row for each column.

**Signature**:

```javascript
applyFilters(
    'acrossaiMcpManager.tools.actions',
    actions,      // array<{ column: 'available' | 'added', label, onClick, disabled? }>
    context       // { serverId, serverSlug, added: Set<string>, draft: Set<string> }
)
```

**Contract**:

- Callback receives the current actions array (empty by default) and returns a possibly-extended array.
- Each action entry MUST have `column` ('available' for the left column header, 'added' for the right), `label` (string, escaped by React), and `onClick` (function taking no args; may mutate `draft` via a provided `setDraft` API — see next paragraph).
- The React app passes a stable `setDraft( updater )` accessor via a second argument to `onClick` for actions that need to mutate the draft state. Reading the current draft is via the callback's `context` argument.
- Safe callback failure: throws → console.error → return original actions unchanged.

---

## JS Filter 3 — `acrossaiMcpManager.tools.row`

**Purpose**: Decorate individual row rendering. Third-party plugins can highlight rows, override the badge, or append inline metadata.

**Fires**: Once per row per render pass (memoized on row `name`).

**Signature**:

```javascript
applyFilters(
    'acrossaiMcpManager.tools.row',
    rowDecoration,   // { className?, badge?, prepend?, append? } — all optional
    row,             // { name, label, description, type, category }
    context          // { serverId, serverSlug, side: 'available' | 'added' }
)
```

**Contract**:

- Callback receives the default row decoration (empty object) and returns a possibly-populated decoration.
- `className` — extra CSS class on the row wrapper. Combined with the default class list via space concat.
- `badge` — object `{ label, bg, fg }` overriding the default type badge, or `null` to hide the badge.
- `prepend` / `append` — React elements inserted before/after the row's main content. Escaping is the callback's responsibility.
- Safe callback failure: throws → console.error → return original decoration unchanged.

---

## PHP Action — `acrossai_mcp_tools_changed`

**Purpose**: Observe tool-set changes for audit logs, metrics, notifications, cache invalidation.

**Fires**: Once per applied add + once per applied remove during a successful `POST /servers/{id}/tools`. Idempotent saves fire zero actions.

**Signature**:

```php
do_action( 'acrossai_mcp_tools_changed', array(
    'server_id'    => (int)    $server_id,
    'ability_slug' => (string) $slug,
    'operation'    => (string) $operation,   // 'added' | 'removed'
) );
```

**Contract**:

- The payload is a positional array (single argument to `do_action`). Callbacks receive it via the first parameter.
- `server_id` — the MCP server whose tool set changed.
- `ability_slug` — the specific slug that was added or removed.
- `operation` — string literal `'added'` or `'removed'`. Never any other value.
- Fired AFTER the DB write commits. Consumers can assume the state is durable.
- Payload contains no user IDs, IP addresses, or session identifiers — matches Security Checklist §"No secrets logged".
- **Observer exceptions are ISOLATED by the controller** (FR-031, SEC-020-004 remediation). Each `do_action` fire is individually wrapped in `try/catch`. A throwing observer is caught, its exception is `error_log`'d with the offending slug + server id, and the REST response continues to HTTP 200. Later observers still fire; the DB write is not rolled back. Consumers do NOT need to try/catch defensively for the sake of the response cycle — but SHOULD still handle their own errors gracefully to avoid noisy `error_log` output.

**Example**:

```php
add_action( 'acrossai_mcp_tools_changed', function ( array $payload ) {
    error_log( sprintf(
        'MCP tool %s on server %d: %s',
        $payload['operation'],
        $payload['server_id'],
        $payload['ability_slug']
    ) );
} );
```

---

## Built-in row semantics

The right "Added as tools" column has a top section titled **"Always available (built-in)"** that renders the three MCP-adapter protocol tools (`mcp-adapter/discover-abilities`, `mcp-adapter/get-ability-info`, `mcp-adapter/execute-ability`) as always-visible, non-removable rows. Third-party plugins registering `acrossaiMcpManager.tools.row` callbacks need to know:

- **`side` argument value `'builtin'`** — the third argument passed to `.tools.row` callbacks can be `'available'`, `'added'`, OR `'builtin'`. Callbacks that want to differentiate must inspect `side`.
- **Built-in rows are NOT operator-editable** — the callback's returned decoration is applied, but the action-button slot is replaced with a static "Built-in" label. Filters that inject buttons will be ignored on built-in rows.
- **Type badge**: built-in rows use the `Built-in` badge palette entry (muted amber). Callbacks overriding `badge` on a built-in row will replace the amber badge with the callback's value.
- **The three built-in slugs are hard-coded in `BUILTIN_ABILITIES`** — they cannot be added to, removed from, or filtered. The `acrossaiMcpManager.tools.fields` filter does NOT receive built-in ability metadata; built-ins are internal to the React app and not exposed to third-party mutation.
- **Row-icon override**: built-in rows show a 🔒 lock icon instead of the ✓ green checkmark. The `prepend` decoration field is still respected and rendered before the row content.

## `EXCLUDED_SLUGS` constant (informational — not a public API)

The three MCP-adapter protocol tools are hard-coded in both `src/js/tools.js` and `ToolsController.php`:

```
mcp-adapter/discover-abilities
mcp-adapter/get-ability-info
mcp-adapter/execute-ability
```

**Not filterable**. Third-party plugins CANNOT add slugs to or remove slugs from this set. If a future feature needs to make the list configurable, that's a separate feature.

---

## Requirements Traceability

| Extension point                          | Requirement |
|------------------------------------------|-------------|
| `acrossaiMcpManager.tools.fields`        | Principle V (Extensibility) |
| `acrossaiMcpManager.tools.actions`       | Principle V |
| `acrossaiMcpManager.tools.row`           | Principle V |
| `acrossai_mcp_tools_changed`             | FR-023 |
| safeApplyFilters boundary                | SC-007-adjacent (no white-screen) |
| EXCLUDED_SLUGS hard-coded (not filterable) | FR-025 |
