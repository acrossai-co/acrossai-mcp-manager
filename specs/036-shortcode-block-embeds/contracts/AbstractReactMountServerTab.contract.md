# Contract: `AbstractReactMountServerTab`

**Feature**: F037 (Pivot C) | **Date**: 2026-07-27 | **Plan**: [../plan.md](../plan.md)

Normative reference for the reusable intermediate abstract class extracted per Pivot C. Positioned as the sanctioned extension surface for third-party companion plugins to add React-mount per-server admin tabs to the "Edit MCP Server" screen.

---

## Namespace + File

- **Namespace**: `AcrossAI_MCP_Manager\Admin\Partials\ServerTabs`
- **File**: `admin/Partials/ServerTabs/AbstractReactMountServerTab.php`
- **PHPDoc tag**: `@since 0.1.11` (target release for Pivot C).
- **Parent**: `AbstractServerTab` (F013 tab-shell contract; inherits `render()` + `slug()` + `label()` + `priority()` + `visible_for()` + the noscript-fallback dispatch pattern added in Pivot A of AbstractServerTab).

---

## Intended Consumers

1. **Built-in tabs shipped with acrossai-mcp-manager**: currently only `EmbedsTab` (F037). `AbilitiesTab` + `ToolsTab` migration tracked in Phase-2 GitHub issue.
2. **Third-party companion plugins** adding their own tabs. This is the primary motivation — extend one class, override 5 methods, call `MyTab::register()` from your plugin's boot code.

---

## Abstract Base Shape

```php
abstract class AbstractReactMountServerTab extends AbstractServerTab {

    // Idempotency guard for register()
    private static array $registered = array();

    // ── Subsystem 1: Registration ────────────────────────────────
    public static function register(): void;
    abstract public static function instance();

    // ── Subsystem 2: Asset enqueue ───────────────────────────────
    abstract public function get_asset_handle(): string;
    abstract public function get_asset_manifest_path(): string;
    abstract public function get_asset_script_url(): string;
    abstract public function get_localize_object_name(): string;

    public function get_asset_style_url(): string;                            // default ''
    public function build_bootstrap_payload( int $server_id, array $server ): array;  // default: state + [serverId, namespace, nonce]
    final public function enqueue_assets_if_active(): void;
    protected function is_active_screen(): bool;                              // screen ID + ?tab= guard

    // ── Subsystem 3: REST controller ─────────────────────────────
    abstract public function get_rest_route_path(): string;

    public function get_rest_namespace(): string;                             // default 'acrossai-mcp-manager/v1'
    public function get_rest_capability(): string;                            // default 'manage_options'
    public function get_save_request_args(): array;                           // default []
    final public function register_rest_routes(): void;
    final public function rest_permission_callback();
    final public function rest_read( WP_REST_Request $request );
    final public function rest_save( WP_REST_Request $request );
    protected function find_server_row( int $server_id );

    // ── Subsystem 4: State contract ──────────────────────────────
    abstract public function get_state_for_server( int $server_id ): array;
    abstract public function set_state_for_server( int $server_id, array $submitted ): array;

    // ── Subsystem 5: Noscript summary (auto-derived from state) ──
    public function summary_rows_from_state( array $state ): array;           // default: naive flatten
    public function get_noscript_summary_rows( array $server ): array;        // calls summary_rows_from_state( get_state_for_server( id ) )
}
```

**Method contract summary**:

| Kind | Count | Notes |
|------|-------|-------|
| MUST-override abstract | 8 | `instance` + 3 asset methods + `get_rest_route_path` + 2 state methods + (inherited) `slug` + `label` |
| SHOULD override | 3 | `get_asset_style_url`, `get_localize_object_name` — well, `get_localize_object_name` is abstract; the SHOULD ones are only `get_rest_namespace` + `get_rest_capability` + `get_save_request_args` |
| MAY override | 4 | `build_bootstrap_payload`, `summary_rows_from_state`, `get_noscript_notice` (inherited), `get_react_root_id` (inherited) |
| `final` (base owns) | 4 | `enqueue_assets_if_active`, `register_rest_routes`, `rest_permission_callback`, `rest_read`, `rest_save` |

---

## Instance Method Contracts

### `public static function register(): void`

- **Post**: Idempotent per subclass (guarded via `self::$registered[static::class]`). Safe to call multiple times.
- **Post**: Wires 3 hooks on the calling plugin's behalf:
  1. `add_action( 'admin_enqueue_scripts', [$instance, 'enqueue_assets_if_active'] )` — asset enqueue guarded by screen + `?tab=` check.
  2. `add_action( 'rest_api_init', [$instance, 'register_rest_routes'] )` — GET + POST route registration.
  3. `add_filter( 'acrossai_mcp_manager_server_tabs', ... )` — appends the tab entry (skipping when the slug is already present in the incoming array — prevents `_doing_it_wrong` when the tab is ALSO seeded by `Registry::all_tabs()`).
- **Consumer contract**: Third-party plugins call `MyTab::register()` from their own boot code, guarded by `class_exists( AbstractReactMountServerTab::class )` — degrades silently when acrossai-mcp-manager is inactive.
- **Constitution note (A1)**: The base itself does not call `add_action` at declaration time — the consuming plugin's boot code calls `register()`, honoring A1 at both plugin levels (each plugin owns its own hook registration entry point).

### `abstract public static function instance()`

- **Post**: Returns the subclass singleton. Subclass MUST implement + a private constructor (A2 pattern).

### `abstract public function get_asset_handle(): string`

- **Post**: Handle used for `wp_enqueue_script/style` + `wp_localize_script`. Should be unique across the WP install. E.g. `'acrossai-mcp-manager-embeds'`, `'my-widgets-tab'`.

### `abstract public function get_asset_manifest_path(): string`

- **Post**: Absolute filesystem path to the webpack `*.asset.php` manifest. Base reads this file via `include` to extract `['dependencies']` + `['version']`.
- **Post**: Empty string OR missing file → base silently skips enqueue (FR-019 pattern — no _doing_it_wrong on missing build).

### `abstract public function get_asset_script_url(): string`

- **Post**: Public URL of the JS bundle. Passed to `wp_enqueue_script`.

### `public function get_asset_style_url(): string` (default `''`)

- **Post**: Public URL of the CSS bundle. Empty string = no CSS enqueue.

### `abstract public function get_localize_object_name(): string`

- **Post**: Name of the `window.*` global the React app reads its bootstrap from. Passed as the 2nd arg to `wp_localize_script()`.

### `public function build_bootstrap_payload( int $server_id, array $server ): array` (default: state + REST fields)

- **Post default**: Returns `get_state_for_server( $server_id ) + [ 'serverId' => $server_id, 'namespace' => $this->get_rest_namespace(), 'nonce' => wp_create_nonce( 'wp_rest' ) ]`.
- **Post**: Subclass MAY override to inject extra fields React needs at first render (e.g. server slug, ancillary metadata).

### `final public function enqueue_assets_if_active(): void`

- **Post**: Guards on `is_active_screen()`; short-circuits if not the plugin's server-edit screen with matching `?tab=`.
- **Post**: Reads asset manifest, enqueues script + optional CSS, resolves `server_id` from `$_GET['server']`, hydrates `$server` via `find_server_row()` when possible, calls `build_bootstrap_payload()`, localizes it.
- **Post**: `final` — subclasses SHOULD NOT override this method; override the declarative getters instead.

### `protected function is_active_screen(): bool`

- **Post**: Returns `true` when the current `get_current_screen()` is in `AdminPageSlugs::plugin_screen_ids()` AND `?action=edit` AND `?tab={$this->slug()}`.

### `abstract public function get_rest_route_path(): string`

- **Post**: REST route path pattern. MUST include a named `(?P<server_id>\d+)` capture. E.g. `'/servers/(?P<server_id>\d+)/embeds'`.

### `public function get_rest_namespace(): string` (default `'acrossai-mcp-manager/v1'`)

- **Post**: REST namespace. Third-party plugins SHOULD override to their own (e.g. `'my-widgets/v1'`).

### `public function get_rest_capability(): string` (default `'manage_options'`)

- **Post**: WordPress capability the REST routes require. Override for a different permission model.

### `public function get_save_request_args(): array` (default `[]`)

- **Post**: REST args schema for the POST body. Merged with the mandatory `server_id` arg by the base. Subclass declares its own payload shape here.

### `final public function register_rest_routes(): void`

- **Post**: Registers GET + POST routes at `get_rest_route_path()` under `get_rest_namespace()` with `rest_permission_callback()` and the subclass's `get_save_request_args()` schema.

### `final public function rest_permission_callback()`

- **Post**: `current_user_can( get_rest_capability() )` → `true`, else `WP_Error('rest_forbidden', ..., ['status' => 403])`.

### `final public function rest_read( WP_REST_Request $request ): WP_REST_Response|WP_Error`

- **Post**: Validates server exists (`find_server_row`). Returns `WP_REST_Response( get_state_for_server( $server_id ), 200 )`.

### `final public function rest_save( WP_REST_Request $request ): WP_REST_Response|WP_Error`

- **Post**: Validates server exists. Calls `set_state_for_server( $server_id, $request->get_params() )`. Returns the fresh state as `WP_REST_Response( ..., 200 )`.

### `protected function find_server_row( int $server_id ): array|WP_Error`

- **Post**: Wraps `MCPServer\Query::instance()->query( ['id' => $server_id, 'number' => 1] )`. Returns row array on hit, `WP_Error('rest_server_not_found', ..., ['status' => 404])` on miss.
- **Post**: `protected` so subclasses' state methods can call it directly when they need server metadata beyond the id.

### `abstract public function get_state_for_server( int $server_id ): array`

- **Post**: Returns the fully-hydrated per-server state. Consumed by REST GET response + `build_bootstrap_payload()` + `get_noscript_summary_rows()`.
- **Post**: Shape is subclass-defined. Consumer decides storage backend (dedicated table / meta blob / options / external service).

### `abstract public function set_state_for_server( int $server_id, array $submitted ): array`

- **Post**: Persist submitted state and return the freshly-hydrated state so the REST POST response echoes it back without a second GET.
- **Post**: Consumer decides schema validation, observability events, cache flushes. Typical body:
  1. Validate `$submitted`
  2. Diff against current state (call `get_state_for_server()`)
  3. Write changed values to storage
  4. Fire per-change observability actions (fail-forward per R3)
  5. Flush any per-request caches
  6. `return $this->get_state_for_server( $server_id );`

### `public function summary_rows_from_state( array $state ): array` (default: naive flatten)

- **Post default**: Flattens top-level state keys as `{label => key, value => stringified}`. Scalars stringified directly; booleans → "Enabled"/"Disabled"; arrays → "N items".
- **Post**: Subclass MAY override for a domain-shaped view (e.g. `EmbedsTab` overrides to produce "Master toggle: Enabled" + per-transport enabled counts).

### `public function get_noscript_summary_rows( array $server ): array`

- **Post**: Base override — calls `summary_rows_from_state( get_state_for_server( $server['id'] ) )`. Zero subclass code required for a working (if naive) noscript fallback.

---

## Third-Party Consumer Contract

The class-level docblock in the shipped file contains a full copy-pastable "WidgetsTab" third-party example. Summary of the extension contract:

1. Extend `AbstractReactMountServerTab`
2. Implement singleton pattern (`instance()` + private constructor)
3. Override the 8 MUST methods: `slug`, `label`, `get_asset_handle`, `get_asset_manifest_path`, `get_asset_script_url`, `get_localize_object_name`, `get_rest_route_path`, `get_state_for_server` + `set_state_for_server`
4. Optionally override the MAY methods for asset CSS, bootstrap payload, noscript view, REST namespace/capability/args
5. From plugin boot code, call `MyTab::register()` guarded by `class_exists( AbstractReactMountServerTab::class )`

Estimated LOC: ~40 lines of PHP declaration + your own JS/CSS build assets.

---

## Historical

`AbstractReactMountServerTab` was extracted post-`/speckit-implement` per Pivot C. See `../spec.md#post-plan-pivots` for the motivation + iteration history. Prior to Pivot C, F037 shipped:
- `admin/Partials/ServerTabs/EmbedsTab.php` — direct subclass of `AbstractServerTab` with a large `render_body()` + noscript override
- `includes/REST/EmbedsController.php` — bespoke REST controller (~400 lines)
- `admin/Main.php::maybe_enqueue_embeds_app()` — bespoke enqueue helper (~90 lines)

Post-Pivot-C, `EmbedsController.php` is deleted; its logic is folded into `EmbedsTab::get_state_for_server()` + `set_state_for_server()`. `maybe_enqueue_embeds_app()` is deleted; the base handles it. `EmbedsTab.php` shrinks to pure identity + config + state methods (~350 lines).
