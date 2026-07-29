# Phase 1 — Data Model

**Feature**: F038 — User-Accessible MCP Servers Shortcode + Reusable Base Class
**Persistent storage**: **NONE** — F038 introduces zero DB tables, zero options, zero meta keys.

All F038 data is a **request-scoped, in-memory projection** built by `AbstractUserServersRenderer::get_accessible_servers()`. The projection filters and reshapes existing persistent entities from F011 (`wp_acrossai_mcp_servers`), F015 (`wp_mcp_access_control` via wpb-access-control vendor), and F037 (`wp_acrossai_mcp_servers_meta`) — but never mutates any of them.

---

## In-Memory Projection Entities

### 1. `AccessibleServer` (projection root)

A per-request projection of an `MCPServer` row filtered by (a) F015 access-control verdict for the current user, (b) F037 embeds master toggle, (c) at least one F035 DTO passing the F037 per-DTO gate.

**Field shape**:

| Field | Type | Source | Nullable |
|-------|------|--------|----------|
| `server_id` | `int` (>0) | `MCPServer::$id` (cast via `(int)`) | No |
| `server_slug` | `string` | `MCPServer::$server_slug` | No |
| `server_name` | `string` | `MCPServer::$server_name` | No |
| `description` | `string` | `MCPServer::$description` (may be empty) | No — empty string when unset |
| `transports` | `list<AccessibleTransport>` | Derived — see AccessibleTransport | No — always non-empty (server dropped upstream if empty) |

**Ordering rule**: sort ascending by `server_name` (case-insensitive natural comparison via `strnatcasecmp` semantics).

**Cardinality**: 0..N per request. Typical fleet: 1–20.

**Invariants**:
- `server_id > 0`
- `server_slug` matches `^[a-z0-9-]+$` (enforced by F011 Schema at write time)
- Every included server has `is_enabled = 1` AND passed the F015 gate AND has at least one entry in `transports`
- No two entries share the same `server_id`

**Lifecycle**: created inside `get_accessible_servers()`, returned by reference, released when the call goes out of scope. Not memoized between calls (upstream F015 + F037 memoization handles the underlying reads).

---

### 2. `AccessibleTransport` (nested under AccessibleServer)

A per-request projection of an `AbstractEmbedTransport` filtered to only the DTOs that pass the per-DTO gate for the current `(server_id, transport_key, dto_slug)` triple.

**Field shape**:

| Field | Type | Source | Nullable |
|-------|------|--------|----------|
| `key` | `string` | `$transport->get_transport_key()` — e.g. `'client'`, `'npm'`, `'ai_connector'`, third-party | No |
| `label` | `string` | `$transport->get_checkbox_label()` (already `__()`-wrapped) | No |
| `priority` | `int` | `$transport->get_priority()` | No |
| `dtos` | `list<AccessibleDTO>` | Filtered — see AccessibleDTO | No — always non-empty (transport dropped upstream if empty) |

**Ordering rule**: preserved from `AbstractEmbedTransport::get_all_registered_transports()` — priority-ASC, ties broken by `transport_key` ASC (per F037 SEC-037-002 comparator).

**Cardinality per AccessibleServer**: 0..N (typically 1–4).

**Invariants**:
- `key` matches `/\A[a-z0-9_-]{1,64}\z/` (F037 regex per bugfix B1 — includes underscore for `ai_connector`)
- Every included transport has at least one entry in `dtos`
- No two entries share the same `key` within a single `AccessibleServer`

---

### 3. `AccessibleDTO` (nested under AccessibleTransport)

An F035 DTO that passed `AbstractEmbedTransport::is_enabled_for_server( $server_id, $transport_key, $dto_slug )` for the current user's context.

**Field shape**: matches F035 DTO shape verbatim (spec.md §Requirements FR-013; also F035 `ConnectionMethodRegistry` contract).

| Field | Type | Source | Nullable |
|-------|------|--------|----------|
| `slug` | `string` | F035 DTO `slug` — e.g. `'claude-desktop'`, `'chatgpt'`, `'npx-acrossai-mcp-manager'` | No |
| `name` | `string` | F035 DTO `name` (translated) | No |
| `icon` | `string` | F035 DTO `icon` — emoji, short marker, or URL | No — empty when unset |
| `description` | `string` | F035 DTO `description` (translated) | No — empty when unset |
| `meta` | `array<string, mixed>` | F035 DTO `meta` (category-specific extras) | No — empty array when unset |

**Ordering rule**: preserved from `$transport->get_dtos()` emission order (F035 sorts by slug ASC within each category; F038 does not re-sort).

**Cardinality per AccessibleTransport**: 1..N (typically 1–8 clients, 1 NPM entry, 0–4 connectors).

**Invariants**:
- `slug` matches `/\A[a-z0-9-]{1,64}\z/` (F034 regex — hyphens only; F038 defensively skips DTOs with non-string slugs per FR-005)
- Every included DTO returned `true` from `AbstractEmbedTransport::is_enabled_for_server( $server_id, $transport_key, $slug )` at the time of projection

---

## Data Flow Diagram

```
┌─────────────────────────────────────┐
│ AbstractUserServersRenderer         │
│ ::get_accessible_servers($user_id)  │
└──────────────┬──────────────────────┘
               │ 1. anonymous short-circuit
               │    (if $user_id ≤ 0 → return [])
               ▼
┌─────────────────────────────────────┐
│ MCPServerQuery::instance()          │
│ ->query([is_enabled=1, number=-1])  │ ← F011 (wp_acrossai_mcp_servers)
└──────────────┬──────────────────────┘
               │ 2. enabled-server row set
               ▼ (for each row)
┌─────────────────────────────────────┐
│ AcrossAI_MCP_Access_Control         │
│ ::instance()                        │
│ ->user_has_server_access(...)       │ ← F015 (wp_mcp_access_control + fail-open)
└──────────────┬──────────────────────┘
               │ 3. if false → skip server
               ▼
┌─────────────────────────────────────┐
│ AbstractEmbedTransport              │
│ ::get_all_registered_transports()   │ ← F037 (canonical enumeration)
└──────────────┬──────────────────────┘
               │ 4. transport instances
               ▼ (for each transport, each DTO)
┌─────────────────────────────────────┐
│ AbstractEmbedTransport              │
│ ::is_enabled_for_server(...)        │ ← F037 (master + per-DTO, R2-memoized)
└──────────────┬──────────────────────┘
               │ 5. if false → skip DTO
               │    if no DTOs survive → skip transport
               │    if no transports survive → skip server
               ▼
┌─────────────────────────────────────┐
│ In-memory AccessibleServer[]         │
│ (sorted by server_name)             │
└──────────────┬──────────────────────┘
               │ 6. apply_filters(
               │      'acrossai_mcp_user_accessible_servers',
               │      $data, $user_id
               │    )
               ▼
       returned to caller
```

Every arrow labeled "F0##" is a **delegation** — F038 owns none of these reads. FR-023 + FR-024 grep-gates enforce this at review time.

---

## Persistent Entities Consumed (read-only, no schema changes)

For completeness — these are the upstream tables/rows F038's projection derives from:

### `wp_acrossai_mcp_servers` (F011)
- Read via `MCPServerQuery::instance()->query()`. Filter: `is_enabled = 1`.
- Fields consumed by F038: `id`, `server_slug`, `server_name`, `description`.

### `wp_mcp_access_control` (F015 — wpb-access-control vendor table)
- Read via `AcrossAI_MCP_Access_Control::instance()->user_has_server_access( $user_id, $server_id )`.
- F038 never reads directly. Vendor manager owns the read + memoization + fail-open.

### `wp_acrossai_mcp_servers_meta` (F037 — WP-canonical meta table per A19)
- Read via `AbstractEmbedTransport::is_enabled_for_server()` which internally reads `_embeds_enabled` + `_embeds_clients` meta keys.
- F038 never reads directly. FR-024 grep-gate.

---

## State Transitions

F038's projection is stateless — it recomputes fresh on every `get_accessible_servers()` call. Upstream state changes are reflected on the very next render:

| Trigger | Effect on next `get_accessible_servers()` return |
|---------|--------------------------------------------------|
| Server row created + `is_enabled=1` set | Appears (if user has F015 access + embeds toggles pass) |
| Server row `is_enabled` flipped to 0 | Disappears |
| Server row deleted | Disappears (row-not-found from Query) |
| F015 rule added denying user | Server disappears |
| F015 rule removed | Server reappears |
| F037 master toggle `_embeds_enabled` → `'1'` | Server may appear (subject to DTO gates) |
| F037 master toggle `_embeds_enabled` → absent | Server disappears |
| F037 per-DTO toggle changed | DTO appears / disappears; transport / server drops if empty |
| Companion plugin registers a 4th transport via `acrossai_mcp_embed_transports` | Transport surfaces automatically (SC-005) — no F038 code change |

No F038 code participates in these transitions — they are pure reads that observe upstream state.

---

**Phase 1 data model result**: no persistent schema. Projection shape locked at FR-013 in spec.md. Ready for contracts + quickstart.
