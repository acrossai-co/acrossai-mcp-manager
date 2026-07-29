# Contract — `AbstractUserServersRenderer`

**File**: `public/Renderers/UserServers/AbstractUserServersRenderer.php`
**Namespace**: `AcrossAI_MCP_Manager\Public\Renderers\UserServers`
**Stability**: `@experimental May change without notice before 1.0.0` per DEC-CLIENT-RENDERER-PUBLIC-API
**Kind**: `abstract class` — extension surface for companion plugins

---

## Class shape

```php
namespace AcrossAI_MCP_Manager\Public\Renderers\UserServers;

use AcrossAI_MCP_Manager\Includes\AccessControl\AcrossAI_MCP_Access_Control;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Embeds\AbstractEmbedTransport;

/**
 * @experimental May change without notice before 1.0.0.
 */
abstract class AbstractUserServersRenderer {

    /**
     * Enumerate every MCP server the given user can access whose F037 Embeds
     * master toggle is ON and which has at least one enabled DTO across any
     * registered transport.
     *
     * @param int|null $user_id Target user's WP user id. NULL / 0 / negative
     *                          → returns []. Defaults to get_current_user_id().
     * @return array<int, array{
     *     server_id:   int,
     *     server_slug: string,
     *     server_name: string,
     *     description: string,
     *     transports:  array<int, array{
     *         key:      string,
     *         label:    string,
     *         priority: int,
     *         dtos:     array<int, array{
     *             slug:        string,
     *             name:        string,
     *             icon:        string,
     *             description: string,
     *             meta:        array<string, mixed>,
     *         }>,
     *     }>,
     * }>
     */
    public function get_accessible_servers( ?int $user_id = null ): array {
        // See "Algorithm" below for the required order of operations.
    }
}
```

---

## Algorithm (required order of operations)

1. **Resolve user id**
   - If `null === $user_id` → `$user_id = get_current_user_id();`
   - If `$user_id <= 0` → return `[]` **immediately**. Do NOT touch DB.

2. **Enumerate enabled servers**
   - Call `MCPServerQuery::instance()->query( [ 'is_enabled' => 1, 'number' => -1 ] )`.
   - On empty result → return `[]`.

3. **Enumerate transports once**
   - Call `AbstractEmbedTransport::get_all_registered_transports()` — cache result in a local `$transports` variable for the duration of this call.
   - Do **NOT** re-fire `acrossai_mcp_embed_transports` inside F038 (FR-023 grep-gate).

4. **For each server row**
   - a. **Access-control gate**: `if ( ! AcrossAI_MCP_Access_Control::instance()->user_has_server_access( $user_id, (int) $row->id ) ) continue;`
   - b. **Per-transport iteration**: For each `$transport` in `$transports`:
     - Call `$transport->get_dtos()`.
     - For each `$dto`:
       - Skip iff `! is_string( $dto['slug'] ?? null )`.
       - Skip iff `! AbstractEmbedTransport::is_enabled_for_server( (int) $row->id, $transport->get_transport_key(), (string) $dto['slug'] )`.
       - Otherwise: keep — append the DTO's normalized fields (`slug`, `name`, `icon`, `description`, `meta` — all defaulted to safe strings/arrays if missing) to the transport's `dtos` array.
   - c. If no DTOs survived across any transport → drop the server (do NOT append).
   - d. Otherwise: append the AccessibleServer entry.

5. **Sort servers**
   - `usort( $servers, static fn( $a, $b ) => strnatcasecmp( $a['server_name'], $b['server_name'] ) );`

6. **Fire filter**
   - `$data = apply_filters( 'acrossai_mcp_user_accessible_servers', $data, $user_id );`

7. **Return `$data`**.

---

## Filter contract

### `acrossai_mcp_user_accessible_servers`

- **Fires**: exactly once per `get_accessible_servers()` invocation, immediately before return.
- **Signature**: `apply_filters( 'acrossai_mcp_user_accessible_servers', array $data, int $user_id ): array`
- **Semantics**: consumers may add / remove / mutate entries in `$data`. F038 defensively coerces a non-array return to `[]` on the calling side (`UserServersBlock`).
- **Non-goals**:
  - NOT a place for consumers to fire their own DB queries (perf trap). Consumers who need to gate on additional criteria SHOULD subclass and add the criteria before calling `parent::get_accessible_servers()`.
  - **NOT a gate-bypass surface (SEC-004)**. The filter fires AFTER the F015 + F037 gate cascade — a listener that APPENDS server entries effectively grants unmediated access, because F038 does NOT re-verify appended entries. Consumers that add entries MUST replay the gate cascade themselves: for each appended entry call `AcrossAI_MCP_Access_Control::instance()->user_has_server_access( $user_id, $server_id )` and `AbstractEmbedTransport::is_enabled_for_server( $server_id, $transport_key, $dto_slug )` before adding it. The filter's intended use is **removing / reshaping** entries the cascade already allowed, not adding new ones.

---

## Class-level invariants

- **No singleton**: base is abstract + stateless. Concrete children may adopt singleton if they wish.
- **No hook registration**: constructor MUST NOT call `add_action`, `add_filter`, or `add_shortcode`. Grep-gate on `public/Renderers/UserServers/AbstractUserServersRenderer.php`.
- **No side-effect constructor**: constructor MAY be omitted (implicit default). If present, MUST be empty.
- **No WP globals besides `get_current_user_id()`**: the class does not read `$_GET`, `$_POST`, `$_REQUEST`, `$wpdb`, or any global option.

---

## Consumer contract (for companion plugins)

Companion plugins subclass this base to build their own contexts (BuddyBoss profile tab, WooCommerce My Account, etc.). Documented usage:

```php
<?php
// Example: BuddyBoss profile tab (in companion plugin)

namespace My\Companion;

use AcrossAI_MCP_Manager\Public\Renderers\UserServers\AbstractUserServersRenderer;

final class BuddyBossMcpTab extends AbstractUserServersRenderer {

    public function render_tab_content(): string {
        $user_id = bp_displayed_user_id();
        $servers = $this->get_accessible_servers( $user_id );

        if ( empty( $servers ) ) {
            return '<p>' . esc_html__( 'No MCP servers available.', 'my-companion' ) . '</p>';
        }

        // Companion plugin owns its own rendering — BuddyBoss template tags, etc.
        ob_start();
        foreach ( $servers as $server ) {
            // ... consumer's custom markup ...
        }
        return (string) ob_get_clean();
    }
}
```

**Required consumer behavior**:
- MUST NOT modify F038's data payload before iteration (use the `acrossai_mcp_user_accessible_servers` filter instead if payload mutation is intended).
- MUST escape all string values at their own render boundary — the payload strings are **unescaped** (they are values, not HTML).
- SHOULD guard the `class_exists( AbstractUserServersRenderer::class )` at consumer boot time (F038 may be inactive on some deployments).

### Caller-authority responsibility (SEC-001)

When a consumer calls `get_accessible_servers( $target_user_id )` where `$target_user_id !== get_current_user_id()`, the consumer MUST independently verify that the **current viewer** is authorized to see the target user's information. F038 does NOT gate the caller — it evaluates the F015 access-control gate FOR the target user, not against the calling user's authority.

The "allowed set per user" is meta-information about access-control policy. Leaking it to an unauthorized viewer would let an attacker map out AC rules without needing admin capabilities.

Typical caller-side guards:

- **Admin views** (rendering another user's list for support / audit): `current_user_can( 'edit_user', $target_user_id )`.
- **BuddyPress profile tabs**: `bp_is_my_profile()` for a "my servers" tab (only the profile owner sees it), or a group-membership check for a moderator-visible summary.
- **WooCommerce "My Account" endpoint**: WooCommerce already gates the endpoint to the account owner; a raw `wc_get_customer_orders_endpoint_url()`-style call is safe.

If a consumer omits this gate and lets any logged-in visitor pass an arbitrary `$target_user_id`, they have built a cross-user access-control-policy enumeration surface. That is a **consumer defect**, not an F038 defect — but F038 documents it here so implementers are forewarned.

---

## Test contract (matches spec.md FR-027)

The `AbstractUserServersRendererTest` PHPUnit case MUST cover:

| Test | Precondition | Assertion |
|------|--------------|-----------|
| `test_anonymous_returns_empty` | `$user_id = 0` | Returns `[]`, zero DB reads |
| `test_no_servers_returns_empty` | No `is_enabled=1` rows | Returns `[]` |
| `test_master_toggle_off_drops_server` | Server exists, no `_embeds_enabled` meta | Returns `[]` |
| `test_zero_dtos_drops_server` | Server exists, master ON, `_embeds_clients` empty JSON | Returns `[]` |
| `test_one_dto_enabled_includes_server` | Server exists, master ON, one client slug enabled | Returns one server with one transport with one DTO |
| `test_f015_deny_drops_server` | Server + master ON + DTOs enabled, F015 rule denies user | Returns `[]` |
| `test_f015_fail_open_when_package_absent` | wpb-access-control class not loaded | Returns servers gated only by F037 toggles |
| `test_filter_round_trip` | Hook `acrossai_mcp_user_accessible_servers` to mutate payload | Return value reflects the mutation |
| `test_sort_by_server_name_case_insensitive` | Three servers named `'zebra'`, `'Alpha'`, `'beta'` | Return order `Alpha, beta, zebra` |
| `test_transport_priority_order_preserved` | Master ON + DTOs enabled across all 3 transports | `transports[]` order is `npm(10), client(20), ai_connector(30)` |
| `test_dto_with_missing_slug_dropped` | DTO with no `slug` key registered via test filter | DTO excluded silently, other DTOs preserved |
