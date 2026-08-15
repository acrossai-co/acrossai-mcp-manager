# Data Model: MCP Quick Setup Wizard

Feature 069 introduces **zero new tables and zero new wp_options**. All persistence is via **two transients** + reuse of existing entities through existing APIs.

## E1 — Wizard Scratchpad (NEW, ephemeral)

**Storage**: WordPress transient (`wp_options` under `_transient_*` when object-cache absent; object-cache backend otherwise).
**Key**: `acrossai_mcp_manager_quick_setup_state_{user_id}` (dynamic per-user suffix).
**TTL**: 30 minutes (refreshed on every write).
**Isolation**: per-user by key construction; users cannot read each other's scratchpads through any wizard code path.

**Shape** (PHP array serialized to string by WP transient API):

```php
array(
    'current_step'    => 1|2|3|4|5|'done',   // string 'done' distinguishes completion from step 5
    'server_id'       => int|null,           // set in Step 1 (existing selection OR new-server create response)
    'access_saved'    => bool,               // Step 2 — true once the AC editor has fired onSave at least once
    'abilities_saved' => bool,               // Step 3 — true when "Enable all" or explicit skip
    'enabled'         => bool,               // Step 4 — mirrors is_enabled at the time of last save (used by App.jsx auto-skip logic)
    'method'          => 'connectors'|'client'|'npm'|'wpcli'|null, // Step 5 pick; null until picked
    'created_at'      => int,                // unix timestamp — used for observability if operator ever wants to log abandonment
)
```

**Lifecycle**:
1. **Created** on first `POST /quick-setup/step` for a user with no existing scratchpad. Defaults populate to sensible zeros.
2. **Updated** on every step-advance (`POST /quick-setup/step`) — the client writes the delta; the server merges + rewrites the full array with a fresh TTL.
3. **Read** on every `GET /quick-setup/state` — returned inside the response as `wizardState`.
4. **Deleted** on `POST /quick-setup/complete` OR natural TTL expiry (30 min after last write).

**Validation rules**:
- `current_step` MUST be one of the 6 enumerated string values; server rejects other values with `400 Bad Request`.
- `server_id` MUST reference a row in `wp_acrossai_mcp_servers` (existence check via `MCPServerQuery` on POST). If the row no longer exists at write time, the server returns `410 Gone` and the client MUST restart the wizard.
- `method` MUST be one of the 4 enumerated strings when non-null.

**No cross-user query** — every read/write includes the current `user_id` in the key; no admin surface enumerates scratchpads.

---

## E2 — Activation Redirect Signal (NEW, ephemeral)

**Storage**: WordPress transient.
**Key**: `acrossai_mcp_manager_quick_setup_do_redirect` (single site-level key).
**TTL**: 30 seconds.
**Isolation**: site-level (no per-user scoping — only one plugin activation can be in flight at once per site).

**Shape**: literal string `'1'` (a boolean flag; presence = "redirect on next admin_init").

**Lifecycle**:
1. **Created** inside `acrossai_mcp_manager_activate()` after the plugin's existing vendor-autoload guard has passed (activation-hook priority 10). See `research.md` R4.
2. **Consumed** by `ActivationRedirect::maybe_redirect()` on `admin_init` at priority 5 in the very next admin page load. Handler `delete_transient()` FIRST, then evaluates guards (bulk-activation, network-activation, capability), then `wp_safe_redirect` → `exit`.
3. **Expires** naturally after 30 seconds if the admin_init consumer never runs (e.g., activation via WP-CLI without a subsequent admin request; user logs out immediately after activation).

**Validation rules**: none — presence-only flag; the value is inspected only for existence.

**Concurrency**: single-writer (the activation hook). Consumer `delete_transient` is idempotent — if two admin_init cycles race, only the first fires the redirect.

---

## E3 — MCP Server (EXISTING — read + write)

**Existing table**: `{$wpdb->prefix}acrossai_mcp_servers` (BerlinDB Kern) — schema unchanged by this feature.
**Reads**: `MCPServerQuery::instance()->query()` — used by `GET /quick-setup/state` to build the Step 1 server list.
**Writes**:
- `MCPServerQuery::instance()->add_item()` — Step 1 "Create a new server" flow.
- `MCPServerQuery::instance()->update_item($id, ['is_enabled' => 1])` — Step 4 enable.

**Wizard-visible fields** (spec § "Wizard-visible sample rows"):
- `id` → wizard `server_id`
- `server_name` → row title
- `server_slug` → used to derive method commands (npm CLI args, WP-CLI subcommand)
- `server_route_namespace`, `server_route` → composed into `<namespace>/<route>` for row display + Step 5 Connectors MCP URL
- `is_enabled` → drives Step 4 auto-skip (FR-017)

**Validation on wizard writes**: `MCPServerQuery` sanitizes at the boundary. Wizard MUST additionally `sanitize_text_field` / `sanitize_title` fields before handing to `add_item()` (belt-and-suspenders per Constitution §III).

**No schema drift**: wizard MUST NOT add columns or indexes. Verified post-implementation by grep-gate FR-030.

---

## E4 — Access Control Rule (EXISTING — read + write via F015 wrapper)

**Existing storage**: `{$wpdb->prefix}mcp_access_control` (wpb-ac v3.x table). Schema unchanged.
**Reads**: via the `AccessControlEditor` React component (extracted in Phase 1 — see research.md R7) that hits wpb-ac's REST layer.
**Writes**: same React component, same wpb-ac REST layer.

**Wizard's role**: mount the extracted `<AccessControlEditor server_id={id} onSave={fn} />` inside Step 2. The wizard's own REST controller MUST NOT reimplement the rule schema — it delegates all AC concerns to the F015 wrapper (per DEC-ACCESS-CONTROL-V2-ADOPTION).

**Validation**: fully owned by wpb-ac + the F015 wrapper. Wizard scratchpad tracks only a boolean `access_saved` flag; the authoritative rule state lives in the AC table.

---

## E5 — Ability Selection (EXISTING — write via F017 REST)

**Existing storage**: `{$wpdb->prefix}acrossai_mcp_server_abilities` (F017 junction table). Schema unchanged.
**Reads (per-server count)**: via `count( wp_get_abilities() )` + optionally the F017 REST GET route for enabled-per-server enumeration.
**Writes**: `POST` to F017's abilities-update route — Step 3 "Enable all abilities for this server" sends the full ability set for the currently-selected server_id.

**Wizard's role**: proxy the "Enable all" bulk action through the F017 controller. Wizard scratchpad tracks only a boolean `abilities_saved` flag.

**Delegation**: MUST route through `ExposureResolver::resolve()` for any read that gates UI state — never re-derive fallback rules (per DEC-ABILITY-OVERRIDE-RESOLUTION).

---

## E6 — Plugin Activation State (READ-ONLY reference)

**Source**: WP core `is_plugin_active()` (requires `require_once ABSPATH . 'wp-admin/includes/plugin.php'` if not loaded).
**Reads**:
- `is_plugin_active( 'acrossai-abilities-manager/acrossai-abilities-manager.php' )` — drives Step 3 dual state.
- `is_plugin_active( 'acrossai-pro/acrossai-pro.php' )` — drives Step 5 Connectors card tri-state.

**Wizard's role**: pure read; surfaced in `GET /quick-setup/state` response under `plugins` key with three possible values per plugin: `'missing'` | `'inactive'` | `'active'` (matching F040 `AIConnectorsPromoTab` shape).

**No writes**: wizard never installs, activates, or deactivates any plugin. Step 5 Connectors card's "Activate AcrossAI Pro" button links to the standard `plugins.php` activate URL (nonce'd via `wp_nonce_url`); the actual activation runs in WP core.

---

## E7 — Connection Method DTOs (READ-ONLY, via F035 registry)

**Source**: `ConnectionMethodRegistry::instance()->get_clients()` / `get_npm_methods()` / `get_ai_connectors()` (F035).
**Reads**: consumed by Step 5's Client pill row, npm command block, and Connectors provider tabs.
**Composition**: per F040 the AI Connectors list of {ChatGPT, Claude, Gemini, Grok} is contributed by the companion `acrossai-ai-connectors` plugin via the `acrossai_mcp_manager_discovery_ai_connectors` filter. Wizard behaves identically whether or not the companion is installed — F035 handles missing-companion gracefully (empty array).

**No writes**: DTOs are read-only registration output.

---

## Entity-relationship diagram

```text
[Wizard Scratchpad] ─── keyed by ──> user_id
        │
        ├── stores ──> server_id ────────┐
        │                                 ▼
        │                        [MCP Server (existing)]
        │                                 │
        │                                 ├── has ──> [Access Control Rule (existing, wpb-ac)]
        │                                 └── has ──> [Ability Selection (existing, F017 junction)]
        │
        └── stores ──> method ──> (label only; no DB write; consumed by Completion screen summary)

[Activation Redirect Signal] ─── site-level ───> consumed by ActivationRedirect handler → wp_safe_redirect
```

## Data-flow summary

| Flow | Trigger | Path |
|---|---|---|
| Wizard hydration | Wizard mount (any step) | `GET /quick-setup/state` → reads scratchpad + servers + abilities count + plugin states |
| Step 1 select existing | Radio click | `POST /quick-setup/step {step:1, data:{server_id}}` → scratchpad update only |
| Step 1 create new | Form submit | `POST /quick-setup/step {step:1, data:{new_server:{…}}}` → `MCPServerQuery::add_item()` → scratchpad update with new server_id → response includes refreshed server list |
| Step 2 rule save | Sub-component onSave | AC editor hits wpb-ac REST directly → wizard's `POST /step {step:2, data:{access_saved:true}}` marks the scratchpad flag |
| Step 3 enable all | Button click | `POST /step {step:3, data:{enable_all_abilities:true}}` → wizard controller POSTs to F017 abilities controller → scratchpad flag set |
| Step 4 enable server | Toggle on | `POST /step {step:4, data:{enabled:true}}` → `MCPServerQuery::update_item(id, ['is_enabled' => 1])` |
| Step 5 method pick | Radio click | `POST /step {step:5, data:{method:'…'}}` → scratchpad update only |
| Wizard complete | Finish click | `POST /quick-setup/complete` → delete scratchpad + first-run signal already consumed at activation → 204 |

**No wizard code path ever bypasses these APIs to touch a DB row directly** — verified by post-implementation grep for `$wpdb->query|prepare|insert|update` under `admin/Partials/QuickSetup/` and `includes/REST/QuickSetupController.php` (expected result: zero hits).
