# Planning: Per-Server Tool Selection (Feature 020)

Replace the per-server **Tools** tab's static reference table (currently
listing the three hardcoded `mcp-adapter/*` protocol tools at
`admin/Partials/ServerTabs/ToolsTab.php:65-94`) with a BerlinDB-backed,
React-rendered **shuttle picker** where operators curate exactly which
registered abilities this server exposes as callable MCP tools. The
shuttle-picker UX — two columns (All abilities / Added as tools) with
per-row Add / Remove, bulk Add all / Remove all, search, counter, empty
state, warning banner, and an explicit `Save changes` / `Cancel` bottom
bar — is defined by the mockup at `tools-ui.zip → Tools Selection.dc.html`
and the accompanying screenshot.

This feature mirrors Feature 017 (`017-per-server-ability-selection.md`)
architecturally — new `MCPServerTool` BerlinDB module, new
`ToolsController` REST endpoint under `/acrossai-mcp-manager/v1/servers/{id}/tools`,
new React bundle `src/js/tools.js` enqueued from
`Admin\Main::maybe_enqueue_tools_app()` — but deliberately diverges on
three UX/semantic points: (a) **presence-based** selection, not per-ability
`is_exposed` booleans — a row in `acrossai_mcp_server_tools` for
`(server_id, ability_slug)` **is** the "added" flag; (b) **explicit batch
Save/Cancel** workflow, not optimistic-per-toggle POSTs; (c) different
visual language — hand-rolled two-column layout rather than
`@wordpress/dataviews`. F017's `MCPServerAbility` module and
`AbilitiesController` are **not** touched. Feature 019's per-server tab
filter surface (`acrossai_mcp_manager_server_tabs`) is unchanged — the
tab retains slug `tools`, priority slot 50, and stays before Abilities
(60) in the tab bar.

The migration is **backwards-compatible with existing installs**: the
Tools tab currently stores no data, so there is nothing to migrate. The
new BerlinDB table lands empty; the effective tool set for every existing
server is initially the empty set (mirrored on the UI as the
"No tools added yet" empty state + inline warning banner). Operators
opt-in by saving a non-empty set. The static reference content
(`Discover Abilities` / `Get Ability Info` / `Execute Ability`) that
today's tab lists is protocol-plumbing owned by the `wordpress/mcp-adapter`
package and is **not** modeled by this feature — it belongs to the
adapter, not to per-server config. The three protocol slugs remain
excluded from the picker's ability pool via the same `EXCLUDED_SLUGS`
convention as F017 (`src/js/abilities.js:73`).

---

## Speckit Workflow

```markdown
# 1. Branch
/speckit.git.feature "per-server-tool-selection"

# 2. Specify
/speckit.specify "Add a per-server Tools tab that lets operators curate
which registered WordPress abilities this MCP server exposes as callable
MCP tools. Replace the current static reference table in
admin/Partials/ServerTabs/ToolsTab.php with a React-rendered shuttle
picker: two columns (All abilities / Added as tools) with per-row Add /
Remove, bulk Add all / Remove all, search box, counter badge, empty
state, zero-added warning banner, wordpress/mcp-adapter cross-link, and
explicit Save changes / Cancel bottom bar. Store the selection in a new
BerlinDB table wp_acrossai_mcp_server_tools with columns id, server_id,
ability_slug, created_at, updated_at (presence-based — a row means the
ability is added as a tool for this server; no is_exposed boolean).
Mirror Feature 017's architecture exactly: new module under
includes/Database/MCPServerTool/{Table,Schema,Query,Row}.php extending
\\BerlinDB\\Database\\Kern\\{Table,Schema,Query,Row}, phantom-version
guard on maybe_upgrade(), singleton Query::instance(), request-time
Table::instance() boot in Main::bootstrap_database_tables() per
DEC-BERLINDB-TABLE-REQUEST-BOOT. New REST controller
includes/REST/ToolsController.php registering
GET /acrossai-mcp-manager/v1/servers/{server_id}/tools returning
{ tools: [ ability_slug, … ], abilities?: […] } (?include_abilities=1
passthrough for cold-start) and POST accepting { tools: [ ability_slug,
… ] } as a full-set replacement (server-side diffs against current rows
and inserts / deletes). manage_options capability. Wire on rest_api_init
via includes/Main::define_admin_hooks(). New React bundle src/js/tools.js
using @wordpress/element + @wordpress/components + @wordpress/api-fetch
+ @wordpress/i18n + @wordpress/hooks with the same safeApplyFilters
boundary as src/js/abilities.js. Bundle enqueued by
Admin\\Main::maybe_enqueue_tools_app() gated by
?page=acrossai_mcp_manager&action=edit&tab=tools, silent-bail on missing
build/js/tools.asset.php, localize window.acrossaiMcpTools with
serverId, serverSlug, restApiRoot (untrailingslashed), nonce (wp_rest),
namespace. Add tools entry to webpack.config.js manual entry map next
to abilities. ToolsTab::render_body outputs the React mount div
id='acrossai-mcp-tools-root' with data-server-id + data-server-slug,
preserving the disabled-server + missing-abilities graceful degrades.
Preserve tab slug 'tools', label 'Tools', priority 50 (already positioned
before Abilities @ 60 in the F019 tab slot map). Exclude the three
mcp-adapter protocol tools (mcp-adapter/discover-abilities,
mcp-adapter/get-ability-info, mcp-adapter/execute-ability) from the
selectable ability pool — mirror the EXCLUDED_SLUGS constant from
src/js/abilities.js. Do not touch Feature 017's MCPServerAbility module
or AbilitiesController. Do not migrate any data — the tab currently has
no persistent state to preserve. Do not modify the F019 filter contract
in admin/Partials/ServerTabs/Registry.php. Memory hygiene per
PATTERN-MEMORY-SUPERSESSION-VS-ANNOTATION: capture two new Active
decisions — one covering presence-based selection modeling, one
covering the explicit Save / Cancel batch commit workflow rationale."
```

### Detailed Description for `/speckit.specify`

> **Before writing a single line of code, read and internalize all of
> these governing documents in full:**
>
> 1. `AGENTS.md` — this plugin's singleton pattern, hook registration
>    rules (A1: no `add_action()` in constructors), Before Commit
>    Checklist, text-domain policy (`acrossai-mcp-manager` on every
>    `__()` / `_n()` / `_x()` call).
> 2. **Feature 017 reference** — the canonical parallel implementation:
>    - `docs/planings-tasks/017-per-server-ability-selection.md`
>    - `admin/Partials/ServerTabs/AbilitiesTab.php`
>    - `includes/Database/MCPServerAbility/{Table,Schema,Query,Row,ExposureResolver}.php`
>    - `includes/REST/AbilitiesController.php`
>    - `src/js/abilities.js` (React bundle pattern, `safeApplyFilters`
>      boundary, `EXCLUDED_SLUGS`, `apiFetch` with nonce middleware,
>      `useSelect` fallback against `core/abilities` store).
>    - `admin/Main.php::maybe_enqueue_abilities_app()` (enqueue guard +
>      manifest read + localize pattern).
> 3. **Feature 019 reference** — per-server tab filter surface:
>    - `docs/planings-tasks/019-per-server-tabs-filter.md`
>    - `admin/Partials/ServerTabs/Registry.php` (priority slot map;
>      Tools stays at 50).
> 4. **Feature 011 reference** — BerlinDB conventions this feature
>    inherits verbatim:
>    - `docs/planings-tasks/011-berlindb-migration.md`
>    - Phantom-version guard: `includes/Database/MCPServerAbility/Table.php:95-100`.
>    - Request-time boot: `includes/Main.php::bootstrap_database_tables()`
>      (add MCPServerTool alongside the three existing modules).
>    - **DEC-BERLINDB-TABLE-REQUEST-BOOT** and
>      **DEC-BERLINDB-SUBCLASS-NO-USE-COLLISION** in
>      `docs/memory/DECISIONS.md`.
> 5. BerlinDB v3 base classes at `vendor/berlindb/core/src/Database/Kern/`
>    — read `Table.php`, `Schema.php`, `Query.php`, `Row.php` for
>    protected-vs-inherited property lists.
> 6. **The mockup** — `tools-ui.zip → Tools Selection.dc.html`. This is
>    the visual contract: two-column shuttle picker, per-row Add /
>    Remove, Add all → header button, Remove all header button, counter
>    "N of M abilities added as tools", search box, type badge
>    (Tool / Prompt / Resource, colored per HTML), zero-added warning
>    banner, mcp-adapter info banner, Save changes + Cancel bottom bar.
>
> Every decision — column definitions, index names, controller endpoint
> shape, React state shape, save-diff algorithm, filter names — must be
> justified against the above. If a choice is not explicitly covered,
> default to the F017 shape.
>
> **Public API artifacts to preserve verbatim** (nothing under these
> paths receives ANY edit in Feature 020 — grep-gate before + after):
>
> - `\AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\*`
> - `\AcrossAI_MCP_Manager\Includes\REST\AbilitiesController`
> - `\AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\AbilitiesTab`
> - `\AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\Registry` (F019 filter
>   contract stays byte-for-byte identical)
> - `src/js/abilities.js`
> - `build/js/abilities*` (untouched — only new `tools*` siblings appear)
>
> Pre-flight grep (records the callers whose behavior must be unchanged
> after the feature — should return nothing outside the tab file itself):
> ```
> grep -rEn '\bToolsTab\b' \
>     --include='*.php' \
>     includes/ admin/ public/ acrossai-mcp-manager.php
> ```
> Every hit here MUST still resolve — same class name, same slug
> `'tools'`, same priority `50`, same label — after every TASK. The one
> file that _internally_ changes is `admin/Partials/ServerTabs/ToolsTab.php`
> itself (`render_body()` body swap only — no signature change).
>
> New table + option map (schema-preservation contract for future features):
>
> | Module | Table (with `$wpdb->prefix`) | `db_version_key` option | `$version` |
> | --- | --- | --- | --- |
> | MCPServerTool | `acrossai_mcp_server_tools` | `acrossai_mcp_server_tools_db_version` | `1.0.0` |
>
> ---
>
> **TASK-1 — MCPServerTool BerlinDB module (new)**
>
> Files:
> - `includes/Database/MCPServerTool/Table.php` (NEW)
> - `includes/Database/MCPServerTool/Schema.php` (NEW)
> - `includes/Database/MCPServerTool/Query.php` (NEW)
> - `includes/Database/MCPServerTool/Row.php` (NEW)
>
> Read `includes/Database/MCPServerAbility/*` (F017) BEFORE writing —
> those four files are the canonical parallel and this module MUST
> mirror them structurally (properties, singleton, phantom-version
> guard, `to_array()` helper) except where explicitly diverged below.
>
> Table subclass — declare protected properties:
> ```php
> protected $name = 'acrossai_mcp_server_tools';
> protected $version = '1.0.0';
> protected $db_version_key = 'acrossai_mcp_server_tools_db_version';
> protected $schema = Schema::class;
> protected $global = false;
> ```
> Singleton `instance(): self`. Override `maybe_upgrade()` verbatim from
> `MCPServerAbility\Table.php:95-100` (phantom-version self-heal):
> ```php
> public function maybe_upgrade(): void {
>     if ( ! $this->exists() ) {
>         delete_option( $this->db_version_key );
>     }
>     parent::maybe_upgrade();
> }
> ```
>
> Schema subclass — five columns, presence-based (no `is_exposed`):
> ```php
> public $columns = array(
>     array( 'name' => 'id', 'type' => 'bigint', 'length' => '20',
>            'unsigned' => true, 'extra' => 'auto_increment',
>            'sortable' => true ),
>     array( 'name' => 'server_id', 'type' => 'bigint', 'length' => '20',
>            'unsigned' => true, 'default' => 0, 'sortable' => true ),
>     array( 'name' => 'ability_slug', 'type' => 'varchar', 'length' => '191',
>            'default' => '', 'searchable' => true ),
>     array( 'name' => 'created_at', 'type' => 'datetime',
>            'default' => 'CURRENT_TIMESTAMP',
>            'sortable' => true, 'date_query' => true ),
>     array( 'name' => 'updated_at', 'type' => 'datetime',
>            'default' => 'CURRENT_TIMESTAMP',
>            'sortable' => true, 'date_query' => true ),
> );
> public $indexes = array(
>     array( 'name' => 'primary', 'type' => 'primary',
>            'columns' => array( 'id' ) ),
>     array( 'name' => 'server_ability', 'type' => 'unique',
>            'columns' => array( 'server_id', 'ability_slug' ) ),
>     array( 'name' => 'server_id', 'type' => 'key',
>            'columns' => array( 'server_id' ) ),
> );
> ```
> `ability_slug` length is 191 (not 255) so the composite UNIQUE key
> fits within InnoDB's 767-byte utf8mb4 limit — same rationale as
> `MCPServerAbility\Schema.php:7`.
>
> Query subclass — declare BerlinDB properties matching F017 pattern:
> ```php
> protected $table_name       = 'acrossai_mcp_server_tools';
> protected $table_alias      = 'mcpst';
> protected $table_schema     = Schema::class;
> protected $item_name        = 'mcp_server_tool';
> protected $item_name_plural = 'mcp_server_tools';
> protected $item_shape       = Row::class;
> ```
> Private constructor + `instance(): self` singleton. Add two bespoke
> helpers on top of BerlinDB's inherited public API:
>
> ```php
> /**
>  * Return the set of ability_slugs currently added as tools for a server.
>  *
>  * @return string[] Ability slugs, order not guaranteed.
>  */
> public function get_added_slugs( int $server_id ): array {
>     $rows = $this->query( array(
>         'server_id' => $server_id,
>         'number'    => 0,
>     ) );
>     return array_map( static fn( $row ) => (string) $row->ability_slug, $rows );
> }
>
> /**
>  * Replace the full set of added ability_slugs for a server.
>  *
>  * Diffs the desired set against the currently-stored set and applies
>  * inserts + deletes. Duplicates within $desired_slugs are collapsed.
>  * Returns the applied diff for logging / actions.
>  *
>  * @param string[] $desired_slugs Full desired set (post-save state).
>  * @return array{added: string[], removed: string[]}
>  */
> public function replace_set( int $server_id, array $desired_slugs ): array {
>     $desired = array_values( array_unique( array_filter( array_map( 'strval', $desired_slugs ), 'strlen' ) ) );
>     $current = $this->get_added_slugs( $server_id );
>     $added   = array_values( array_diff( $desired, $current ) );
>     $removed = array_values( array_diff( $current, $desired ) );
>     foreach ( $added as $slug ) {
>         $this->add_item( array(
>             'server_id'    => $server_id,
>             'ability_slug' => $slug,
>         ) );
>     }
>     foreach ( $removed as $slug ) {
>         $existing = $this->query( array(
>             'server_id'    => $server_id,
>             'ability_slug' => $slug,
>             'number'       => 1,
>         ) );
>         if ( ! empty( $existing ) ) {
>             $this->delete_item( $existing[0]->id );
>         }
>     }
>     return array( 'added' => $added, 'removed' => $removed );
> }
> ```
>
> Row subclass — extend `\BerlinDB\Database\Kern\Row`. Declare public
> properties matching the schema columns (`id`, `server_id`,
> `ability_slug`, `created_at`, `updated_at`) + a `to_array(): array`
> helper for admin / REST serialization. Cast `id` and `server_id` to
> `int` in `to_array()`.
>
> ---
>
> **TASK-2 — Table bootstrap (activation + request-time)**
>
> Files:
> - `includes/Activator.php`
> - `includes/Main.php`
>
> `Activator::activate()` — add ONE line to the existing block of
> `Table::instance()->maybe_upgrade()` calls (line placement: alphabetical
> or after `MCPServerAbility` — pick the same convention F017 established):
> ```php
> use AcrossAI_MCP_Manager\Includes\Database\MCPServerTool\Table as MCPServerToolTable;
> ...
> MCPServerToolTable::instance()->maybe_upgrade();
> ```
> No seeder — presence-based, empty by default is the correct initial state.
>
> `Main::bootstrap_database_tables()` — add ONE line to the existing
> request-time boot block (per DEC-BERLINDB-TABLE-REQUEST-BOOT so
> `$wpdb->prefix . 'acrossai_mcp_server_tools'` is registered with the
> BerlinDB global DB interface):
> ```php
> \AcrossAI_MCP_Manager\Includes\Database\MCPServerTool\Table::instance();
> ```
> Add the invocation next to the existing MCPServerAbility boot at
> `includes/Main.php:208` (F017 landed it there).
>
> ---
>
> **TASK-3 — ToolsController REST endpoint (new)**
>
> Files:
> - `includes/REST/ToolsController.php` (NEW)
> - `includes/Main.php` (delta: register the controller on `rest_api_init`)
>
> Read `includes/REST/AbilitiesController.php` (F017) BEFORE writing —
> this controller mirrors its shape: singleton, no hooks in ctor (A1),
> namespace constant, permission check via `manage_options`, per-route
> arg schema.
>
> Namespace: `acrossai-mcp-manager/v1` (same as F017). Routes under
> `/servers/(?P<server_id>\d+)/tools`.
>
> **GET** `/servers/{server_id}/tools` — returns:
> ```php
> array(
>     'tools'      => string[],  // Currently-added ability_slugs
>     // Optional (only when ?include_abilities=1 query param present):
>     'abilities'  => array<int, array{
>         name: string,
>         label: string,
>         description: string,
>         type: string,           // 'Tool' | 'Prompt' | 'Resource' | ''
>         category: string,       // From ability meta if available
>     }>,
> );
> ```
> Populate `abilities` from `wp_get_abilities()` when available (F017's
> fallback pattern). Excluded slugs (three `mcp-adapter/*` protocol
> tools) filtered out of the ability list — reuse the same constant
> from `src/js/abilities.js:73`, defined mirror-side in the controller.
>
> **POST** `/servers/{server_id}/tools` — accepts:
> ```php
> array(
>     'tools' => string[],  // Full desired set (replace-all semantics).
> );
> ```
> Validation: every slug MUST resolve via `wp_get_abilities()` when the
> function exists. If it doesn't (bail-friendly guard), accept the raw
> list — the UI can't produce arbitrary slugs. All-or-nothing batch:
> reject the whole request on any invalid slug (`rest_ensure_response`
> with 400 + human-readable slug list). No partial writes.
>
> On success:
> - Call `MCPServerToolQuery::instance()->replace_set( $server_id, $tools )`.
> - Fire `acrossai_mcp_tools_changed` action per applied add/remove
>   (payload: `[ 'server_id' => int, 'ability_slug' => string,
>   'operation' => 'added'|'removed', ]`).
> - Return refreshed `{ tools: [ … ] }` reflecting DB truth
>   (post-diff — matches F017's FR-010 no-follow-up-GET pattern).
>
> Permission callback: `current_user_can( 'manage_options' )`. Never
> `__return_true` (constitution §III).
>
> Wire in `Main::define_admin_hooks()` — mirror the F017 line:
> ```php
> $tools_rest = \AcrossAI_MCP_Manager\Includes\REST\ToolsController::instance();
> $this->loader->add_action( 'rest_api_init', $tools_rest, 'register_routes' );
> ```
> Place the two lines immediately AFTER the F017
> `$abilities_rest = ...` block at `includes/Main.php:424-425`.
>
> ---
>
> **TASK-4 — ToolsTab render swap (retire the static reference table)**
>
> Files:
> - `admin/Partials/ServerTabs/ToolsTab.php`
>
> Preserve exactly:
> - `slug(): string` returns `'tools'`.
> - `label(): string` returns `__( 'Tools', 'acrossai-mcp-manager' )`.
> - `priority(): int` returns `50`.
> - The `AbstractServerTab` parent (no base-class change).
>
> Rewrite `render_body( array $server ): void`. Replace the current
> lines 65-94 body with:
>
> 1. `$enabled = ! empty( $server['is_enabled'] );`
> 2. Open `<div class="mcp-tab-panel">` and heading `<h2>MCP Tools</h2>`
>    (i18n, text domain `acrossai-mcp-manager`).
> 3. If not enabled: render the same disabled-server warning notice as
>    today, close the div, return.
> 4. If `! function_exists( 'wp_get_abilities' )`: render an inline
>    `notice notice-error` explaining the WordPress Abilities API is
>    unavailable, close the div, return.
> 5. Otherwise: emit the React mount div and a `Loading tools…`
>    placeholder inside it. Match the F017 mount attribute shape
>    (`AbilitiesTab.php:102`):
>    ```php
>    printf(
>        '<div id="acrossai-mcp-tools-root" data-server-id="%1$d" data-server-slug="%2$s"><p class="description">%3$s</p></div>',
>        (int) $server['id'],
>        esc_attr( (string) ( $server['server_slug'] ?? '' ) ),
>        esc_html__( 'Loading tools…', 'acrossai-mcp-manager' )
>    );
>    ```
>
> Delete `get_core_tools()` and `render_tools_table()` — they modeled
> the retired static reference content. Grep the plugin after deletion:
> ```
> grep -rEn '\b(get_core_tools|render_tools_table)\b' \
>     --include='*.php' includes/ admin/ public/
> ```
> Expected result: zero matches. Any hit is a stale reference.
>
> ---
>
> **TASK-5 — Tools React bundle (new)**
>
> Files:
> - `src/js/tools.js` (NEW)
> - `src/scss/tools.scss` (NEW, optional — bundled component styles)
>
> Read `src/js/abilities.js` (F017) BEFORE writing. Reuse:
> - `safeApplyFilters` boundary (F017 defensive filter pattern).
> - `EXCLUDED_SLUGS` set (same three `mcp-adapter/*` protocol slugs).
> - `apiFetch` with nonce middleware pattern.
> - `useSelect` fallback against `core/abilities` store; REST fallback
>   via `?include_abilities=1` when the store is unavailable.
> - `window.acrossaiMcpTools` localize contract (`serverId`, `serverSlug`,
>   `restApiRoot`, `nonce`, `namespace`).
> - Text domain `acrossai-mcp-manager` on every `__()` / `sprintf`.
>
> Diverge on:
> - **UX**: hand-rolled two-column shuttle picker per the mockup — NOT
>   `@wordpress/dataviews`. Match `Tools Selection.dc.html` layout exactly:
>   header row with title + counter badge, two 1fr columns with
>   `border-radius:8px` cards, per-row Add / Remove buttons, bulk header
>   buttons, search input above the left list, empty state on the right,
>   zero-added warning banner + mcp-adapter info banner below, sticky
>   Save changes / Cancel bottom bar.
> - **State shape**:
>   ```javascript
>   const [ added, setAdded ] = useState( new Set() );   // Server truth, seeded from GET
>   const [ draft, setDraft ] = useState( new Set() );   // Local edits
>   const [ search, setSearch ] = useState( '' );
>   const [ loading, setLoading ] = useState( true );
>   const [ saving, setSaving ] = useState( false );
>   const [ error, setError ] = useState( null );
>   ```
>   `draft` is initialized as a clone of `added` on load. Add / Remove /
>   Add all / Remove all mutate `draft`. Save posts `Array.from(draft)`;
>   on success `setAdded(new Set(draft))`. Cancel: `setDraft(new Set(added))`.
>   Disable Save + Cancel buttons when `draft` and `added` are equal
>   (compute via a small helper — same-size + every element present).
> - **No optimistic per-toggle POST** — this is deliberate. Save/Cancel
>   is the design contract.
>
> Extensibility filters (WordPress-side, `@wordpress/hooks`), mirror
> F017's namespace choice:
> - `acrossaiMcpManager.tools.fields` — decorate row shape.
> - `acrossaiMcpManager.tools.actions` — inject header buttons.
> - `acrossaiMcpManager.tools.row` — decorate individual rows.
> All wrapped in `safeApplyFilters()` so a broken third-party callback
> doesn't crash the mount.
>
> Type badge colors — reuse the mockup's palette
> (`Tools Selection.dc.html:178-183`):
> ```javascript
> const TYPE_STYLE = {
>     Tool:     { bg: '#e5f0f8', fg: '#0a4b78' },
>     Prompt:   { bg: '#f3e8fd', fg: '#6b21a8' },
>     Resource: { bg: '#e6f6ec', fg: '#0a6b3d' },
> };
> ```
>
> Search predicate: case-insensitive substring match against
> `ability.name` OR `ability.label` OR `ability.description` OR
> `ability.category` (mockup contract at `support.js` payload).
>
> `EXCLUDED_SLUGS` — same set as F017; filtered from the ability pool
> BEFORE the search predicate runs, so the three `mcp-adapter/*`
> protocol tools are never visible.
>
> ---
>
> **TASK-6 — Webpack entry + admin enqueue**
>
> Files:
> - `webpack.config.js`
> - `admin/Main.php`
>
> `webpack.config.js` — extend the manual entry map (F017 uses this
> pattern for `js/abilities`). Add ONE line to the entry object:
> ```javascript
> 'js/tools': path.resolve( __dirname, 'src/js/tools.js' ),
> ```
> If `src/scss/tools.scss` is created, no config change is needed —
> `@wordpress/scripts` auto-extracts the imported SCSS into
> `build/js/tools.css`.
>
> `admin/Main.php` — add a new `maybe_enqueue_tools_app()` method
> modeled on `maybe_enqueue_abilities_app()` (F017,
> `admin/Main.php:215-280`). Guard: `?page=acrossai_mcp_manager`
> AND `?action=edit` AND `?tab=tools`. Read manifest from
> `build/js/tools.asset.php`; silent bail on missing file (F017 FR-019).
> Handle: `acrossai-mcp-manager-tools`. Optional CSS enqueue if
> `build/js/tools.css` exists (`file_exists` guard). Localize
> `window.acrossaiMcpTools`:
> ```php
> wp_localize_script(
>     'acrossai-mcp-manager-tools',
>     'acrossaiMcpTools',
>     array(
>         'serverId'    => $server_id,
>         'serverSlug'  => $server_slug,
>         'restApiRoot' => untrailingslashit( rest_url() ),
>         'nonce'       => wp_create_nonce( 'wp_rest' ),
>         'namespace'   => 'acrossai-mcp-manager/v1',
>     )
> );
> ```
> Wire the new method into `enqueue_scripts()` beside the existing
> `maybe_enqueue_abilities_app()` call.
>
> ---
>
> **TASK-7 — Tests (PHPUnit + Jest)**
>
> Files:
> - `tests/phpunit/Database/MCPServerTool/QueryReplaceSetTest.php` (NEW)
> - `tests/phpunit/Database/MCPServerTool/PhantomVersionGuardTest.php` (NEW)
> - `tests/phpunit/REST/ToolsControllerTest.php` (NEW)
> - `tests/jest/tools/diffDraftAgainstAdded.test.js` (NEW)
> - `tests/jest/tools/safeApplyFilters.test.js` (NEW — thin wrapper
>   around F017's `tests/jest/abilities/safeApplyFilters.test.js`)
>
> `QueryReplaceSetTest` — must cover:
> - Empty → non-empty: 3 slugs desired, 0 stored → 3 inserts, 0 deletes.
> - Non-empty → empty: 3 stored, empty desired → 0 inserts, 3 deletes.
> - Overlap: `[a,b,c]` stored, `[b,c,d]` desired → 1 insert (d), 1 delete (a).
> - Duplicates in desired collapse: `[a,a,b]` desired → treated as `[a,b]`.
> - Idempotency: same input twice → second call is a no-op, applied diff
>   is `{ added: [], removed: [] }`.
>
> `PhantomVersionGuardTest` — drop the table with the `db_version_key`
> option still stamped, call `Table::instance()->maybe_upgrade()`,
> assert the table exists again. Silent-guard invariant per F011
> Clarification Q1 (no `error_log`, no admin notice, no transient).
>
> `ToolsControllerTest` — must cover:
> - GET without `include_abilities`: response omits `abilities` key.
> - GET with `include_abilities=1`: response includes `abilities` array;
>   excluded `mcp-adapter/*` slugs are absent from the array.
> - GET permission: 403 when the user lacks `manage_options`.
> - POST 200 on valid slug set; response `tools` matches DB truth.
> - POST 400 when any slug fails `wp_get_abilities()` validation;
>   DB unchanged (all-or-nothing).
> - POST fires `acrossai_mcp_tools_changed` once per added + once per
>   removed slug (assert via `add_action` counter).
> - POST idempotent when the desired set equals current storage.
>
> `diffDraftAgainstAdded.test.js` — exercise the pure helper the React
> app uses to compute button disabled-state:
> - Equal sets → returns `{ equal: true }`.
> - Different sizes → returns `{ equal: false }`.
> - Same size, different members → returns `{ equal: false }`.
>
> `safeApplyFilters.test.js` — mirror F017's defensive-boundary test —
> a callback that throws must not crash the React tree; the return
> value is the last known-good value.
>
> ---
>
> **TASK-8 — Memory hygiene + changelog**
>
> Files:
> - `README.txt`
> - `docs/memory/DECISIONS.md`
> - `docs/memory/WORKLOG.md`
> - `docs/memory/INDEX.md`
> - `docs/planings-tasks/README.md`
>
> `README.txt` Unreleased changelog:
> ```
> * Per-server Tools tab: pick which registered abilities each MCP
>   server exposes as callable tools. Two-column shuttle picker with
>   explicit Save changes / Cancel. Stored in a new BerlinDB table
>   wp_acrossai_mcp_server_tools with a self-healing install lifecycle.
>   The three mcp-adapter protocol tools remain built-in and are not
>   configurable per server.
> ```
>
> `docs/memory/DECISIONS.md` — add two new **Active** entries:
>
> - **DEC-TOOL-SELECTION-PRESENCE-MODEL (Active — Feature 020)**:
>   Per-server tool selection is modeled as row presence in
>   `wp_acrossai_mcp_server_tools`, not as an `is_exposed` boolean
>   like F017's abilities table. Rationale: the shuttle-picker UX has
>   no per-ability tri-state (inherited / on / off) — a row exists
>   for `(server_id, ability_slug)` if and only if the ability is
>   currently added as a tool for that server. Simpler storage, simpler
>   diff on Save, no ExposureResolver needed.
> - **DEC-TOOL-EDIT-EXPLICIT-BATCH-COMMIT (Active — Feature 020)**:
>   The Tools tab uses an explicit Save changes / Cancel batch commit
>   workflow, unlike F017's optimistic-per-toggle POST pattern.
>   Rationale: the shuttle picker gives operators an undo affordance
>   (Cancel) that the DataViews toggle grid does not; a full-set POST
>   is cheaper than N per-row POSTs when curating a large exposure
>   list; server-side diff is trivial with `replace_set()`.
>
> Both entries need companion rows in `docs/memory/INDEX.md` under
> **Active Decisions**.
>
> `docs/memory/WORKLOG.md` — add a Feature 020 milestone (Why durable /
> Future mistake prevented / Evidence / Where to look). Highlight the
> durable lesson: **when a UI models "in list / not in list" rather
> than "on / off", the storage should model row presence, not a
> boolean — the boolean introduces a third state ("row exists but
> false") that has no UI representation.**
>
> `docs/planings-tasks/README.md` — append a row for
> `020-per-server-tool-selection.md` to the docs index.
>
> ---
>
> **CONSTRAINTS**
>
> - **Do not touch F017.** `includes/Database/MCPServerAbility/*`,
>   `includes/REST/AbilitiesController.php`,
>   `admin/Partials/ServerTabs/AbilitiesTab.php`, `src/js/abilities.js`,
>   `build/js/abilities*` receive ZERO edits in Feature 020.
> - **Do not touch F019.** `admin/Partials/ServerTabs/Registry.php`
>   and the `acrossai_mcp_manager_server_tabs` filter contract are
>   unchanged. ToolsTab stays at priority slot 50.
> - **Do not migrate data.** The current static-reference Tools tab
>   has no persistent state. The new BerlinDB table lands empty.
>   Every existing server initially has an empty tool set — this is
>   the correct semantic (the UI shows the empty state + warning
>   banner until an operator saves a non-empty set).
> - **Do not skip the phantom-version guard on
>   `MCPServerTool\Table::maybe_upgrade()`.** Same cheap defense as
>   F011 / F017. Silent-guard invariant per Clarification Q1 —
>   no `error_log`, no admin notice, no transient.
> - **Do not add a Table subclass without also booting it in
>   `Main::bootstrap_database_tables()`.** Per DEC-BERLINDB-TABLE-REQUEST-BOOT
>   from F011, missing this triggers the "Table doesn't exist" query
>   fallback bug. Every future BerlinDB table this plugin adds must
>   land in both `Activator::activate()` AND `Main::bootstrap_database_tables()`.
> - **Do not add `use` for a BerlinDB Kern base class whose local
>   subclass has the same name.** Per DEC-BERLINDB-SUBCLASS-NO-USE-COLLISION
>   from F011. Extend via leading-`\` FQN in the four new class files.
> - **Do not change the tab slug or priority.** `slug = 'tools'`,
>   `priority = 50`. Operators bookmark
>   `?page=acrossai_mcp_manager&action=edit&tab=tools`; changing the
>   slug breaks their links.
> - **Do not use `__return_true` for any REST permission callback.**
>   Every route requires an explicit `manage_options` check
>   (constitution §III).
> - **Do not include the three `mcp-adapter/*` protocol tools in the
>   selectable ability pool.** They are protocol plumbing owned by
>   the adapter package, not per-server config. Reuse the F017
>   `EXCLUDED_SLUGS` set.
> - **Do not use a UI library other than `@wordpress/*` packages.**
>   No external React libraries, no CSS-in-JS beyond inline `style`
>   attributes matching the mockup, no TypeScript (this codebase is
>   plain JS + JSX via `@wordpress/scripts`).
> - **Do not hardcode the REST namespace or version in JS.** Read
>   from `window.acrossaiMcpTools.namespace` — matches F017.
> - **Do not touch any file under `vendor/`.** No composer dependency
>   is added or bumped by this feature.
> - **Do not include an ExposureResolver-style fallback layer.** The
>   presence-based model is authoritative for whether a tool is added.
>   If future work needs a "default true" pool (e.g. auto-add all
>   abilities on first install), that lands in a separate feature.
> - **Every task must leave PHPStan level 8 + PHPCS individually
>   green before moving to the next.** Constitution §VII per-task
>   gating applies.
> - **BerlinDB Schema `$columns` MUST be exactly what the initial
>   `CREATE TABLE` statement produces** — no post-install `ALTER`
>   in the same release. Any column-shape change goes in a follow-up
>   feature bump.
> - **Grep after every task** for stale references to the deleted
>   `get_core_tools` / `render_tools_table` methods.
>
> ---
>
> **SECURITY REQUIREMENTS**
>
> - Nonce: verified by `@wordpress/api-fetch` nonce middleware seeded
>   from `wp_create_nonce( 'wp_rest' )` in the localize payload.
> - Capability: `current_user_can( 'manage_options' )` on both GET and
>   POST route callbacks.
> - Server-id boundary: every REST call validates `server_id` resolves
>   to an existing row in `wp_acrossai_mcp_servers`. 404 otherwise.
> - Input validation: all posted `tools` slugs pass through
>   `sanitize_text_field()` before validation; `wp_get_abilities()`
>   membership check when available.
> - Output escaping: every value rendered in ToolsTab.php uses the
>   correct escape function (`esc_html__`, `esc_attr`, `esc_url`).
>   React output is inherently escaped; injected raw HTML is
>   forbidden.
> - No secrets logged. The `acrossai_mcp_tools_changed` action payload
>   contains ability slugs only — no user IDs or IP addresses.

```markdown
# 3. Plan + guard + security
/speckit.memory-md.plan-with-memory
/speckit.architecture-guard.governed-plan
/speckit.security-review.plan

# 4. Tasks + guard
/speckit.tasks
/speckit.architecture-guard.governed-tasks

# 5. Implement + quality checks
/speckit.architecture-guard.governed-implement
composer dump-autoload
composer run phpcs
composer run phpstan
npm run build
npm test

# 6. Review + memory + commit
/speckit.analyze
/speckit.architecture-guard.architecture-review
/speckit.security-review.staged
/speckit.memory-md.capture-from-diff
/speckit.git.commit
```

---

## Manual Verification Checklist

### TASK-1 — MCPServerTool BerlinDB module
- [ ] `includes/Database/MCPServerTool/{Table,Schema,Query,Row}.php`
      all extend the corresponding `\BerlinDB\Database\Kern\*` class
      via leading-`\` FQN (no `use` — DEC-BERLINDB-SUBCLASS-NO-USE-COLLISION).
- [ ] `Table::$version === '1.0.0'` and
      `Table::$db_version_key === 'acrossai_mcp_server_tools_db_version'`.
- [ ] `Table::maybe_upgrade()` contains the phantom-version guard
      (`if ( ! $this->exists() ) { delete_option( $this->db_version_key ); }`
      before `parent::maybe_upgrade()`).
- [ ] `Schema::$columns` matches the five-column definition (id,
      server_id, ability_slug, created_at, updated_at) — no `is_exposed`.
- [ ] `Schema::$indexes` includes PRIMARY(id), UNIQUE(server_id,ability_slug),
      KEY(server_id).
- [ ] `Query::$item_shape === Row::class`; singleton `instance()` returns `self`.
- [ ] `Query::replace_set()` computes and applies the correct
      insert / delete diff (unit test `QueryReplaceSetTest` PASS).
- [ ] `Row::to_array()` casts `id` and `server_id` to `int`.

### TASK-2 — Bootstrap
- [ ] Fresh activation on a clean install:
      `wp db query "SHOW TABLES LIKE 'wp_acrossai_mcp_server_tools'"`
      returns exactly one row.
- [ ] `wp option get acrossai_mcp_server_tools_db_version` returns `1.0.0`.
- [ ] `Main::bootstrap_database_tables()` calls
      `\AcrossAI_MCP_Manager\Includes\Database\MCPServerTool\Table::instance();`
      alongside the other three BerlinDB modules.
- [ ] Reactivation on healthy install produces zero `ALTER TABLE`
      (verify `SHOW WARNINGS` empty + debug.log clean).

### TASK-3 — ToolsController REST endpoint
- [ ] `GET /wp-json/acrossai-mcp-manager/v1/servers/{id}/tools`
      returns `{ tools: [] }` on a server with no rows.
- [ ] `GET ?include_abilities=1` returns
      `{ tools: [...], abilities: [...] }` and the three
      `mcp-adapter/*` protocol slugs are absent from `abilities`.
- [ ] `POST { tools: [ "acrossai-core-abilities/create-post" ] }`
      inserts one row; response mirrors DB truth.
- [ ] `POST { tools: [] }` on a server with N rows deletes all N.
- [ ] `POST` with an unknown ability slug returns 400 with an error
      body naming the invalid slugs; DB unchanged.
- [ ] `POST` fires `acrossai_mcp_tools_changed` once per applied add
      and once per applied remove (empirically counted).
- [ ] REST calls without `manage_options` return 403.

### TASK-4 — ToolsTab render swap
- [ ] `admin/Partials/ServerTabs/ToolsTab.php` no longer contains
      `get_core_tools` or `render_tools_table` (grep zero).
- [ ] The tab still returns `slug()==='tools'`, `label()==='Tools'`,
      `priority()===50`.
- [ ] Rendering on a disabled server shows the disabled-server
      warning notice unchanged.
- [ ] Rendering when `wp_get_abilities()` is unavailable shows an
      inline error notice explaining the WordPress Abilities API is
      missing.
- [ ] Rendering on a healthy enabled server emits the mount div
      `<div id="acrossai-mcp-tools-root" data-server-id="…" data-server-slug="…">`.

### TASK-5 — Tools React bundle
- [ ] `src/js/tools.js` exists and imports only `@wordpress/*` packages.
- [ ] `EXCLUDED_SLUGS` contains exactly the three `mcp-adapter/*`
      protocol slugs.
- [ ] Type badge palette matches the mockup (Tool blue / Prompt purple /
      Resource green).
- [ ] Search predicate filters by name OR label OR description OR
      category, case-insensitive.
- [ ] Add all header button adds every visible-after-search row.
- [ ] Remove all header button empties the right column.
- [ ] Save changes button posts the current draft set, updates the
      server-truth `added` state on success.
- [ ] Cancel button restores `draft` to `added` verbatim.
- [ ] Save + Cancel buttons are disabled when `draft` equals `added`
      (equal size + every element present).
- [ ] Zero-added inline warning banner appears exactly when
      `added.size === 0` post-save.
- [ ] mcp-adapter info banner is always visible below the two columns.

### TASK-6 — Webpack entry + admin enqueue
- [ ] `webpack.config.js` includes the `'js/tools'` manual entry.
- [ ] `npm run build` produces `build/js/tools.js`,
      `build/js/tools.asset.php`, and optionally `build/js/tools.css`.
- [ ] `Admin\Main::maybe_enqueue_tools_app()` silent-bails on missing
      `build/js/tools.asset.php` (no PHP notice).
- [ ] On `?page=acrossai_mcp_manager&action=edit&tab=tools`, the
      handle `acrossai-mcp-manager-tools` is enqueued and
      `window.acrossaiMcpTools` is populated with the five expected
      keys.
- [ ] On any OTHER admin screen, the handle is NOT enqueued
      (verified via view-source).

### TASK-7 — Tests
- [ ] `vendor/bin/phpunit tests/phpunit/Database/MCPServerTool/`
      PASS (both `QueryReplaceSetTest` and `PhantomVersionGuardTest`).
- [ ] `vendor/bin/phpunit tests/phpunit/REST/ToolsControllerTest.php`
      PASS with all seven scenarios above.
- [ ] `npx jest tests/jest/tools/` PASS.

### TASK-8 — Release notes + memory hygiene
- [ ] `README.txt` Unreleased changelog contains the Tools tab bullet.
- [ ] `docs/memory/DECISIONS.md` contains two new **Active — Feature
      020** entries: `DEC-TOOL-SELECTION-PRESENCE-MODEL` and
      `DEC-TOOL-EDIT-EXPLICIT-BATCH-COMMIT`.
- [ ] `docs/memory/WORKLOG.md` contains a Feature 020 milestone.
- [ ] `docs/memory/INDEX.md` has two new Active Decisions rows plus
      the WORKLOG row.
- [ ] `docs/planings-tasks/README.md` lists
      `020-per-server-tool-selection.md`.

### Final full-repo audit (blocker before merge)

```bash
grep -rEn '\b(get_core_tools|render_tools_table)\b' \
    --include='*.php' \
    includes/ admin/ public/ acrossai-mcp-manager.php

grep -rEn 'MCPServerAbility|AbilitiesController' \
    --include='*.php' \
    includes/Database/MCPServerTool/ \
    includes/REST/ToolsController.php \
    admin/Partials/ServerTabs/ToolsTab.php \
    src/js/tools.js
```

- [ ] First grep returns zero matches (retired helpers deleted).
- [ ] Second grep returns zero matches (Tools code path never
      references the F017 module — the two features are architecturally
      independent).

### Quality gates (all must be green before commit)
- [ ] PHPStan level 8 — zero errors.
- [ ] PHPCS — zero errors.
- [ ] `composer test` — PHPUnit all remaining tests pass.
- [ ] `npm run lint:js` — zero errors.
- [ ] `npm run lint:css` — zero errors.
- [ ] `npm test` — Jest all suites pass.
- [ ] `npm run build` succeeds and produces `build/js/tools.js` +
      `build/js/tools.asset.php`.
- [ ] `SHOW TABLES LIKE 'wp_acrossai_mcp_%'` on a clean install
      returns exactly five rows (existing four + the new
      `wp_acrossai_mcp_server_tools`).
- [ ] `SELECT option_name FROM wp_options WHERE option_name LIKE
      'acrossai_mcp%_db_version'` returns exactly five rows.

---

## Pre-flight Attestation

**Attestation**: No production install of this plugin has any Tools tab
state to preserve — today's Tools tab is a static reference table with
no persistent storage. The new BerlinDB table lands empty on activation,
and every existing server begins with an empty tool set (represented on
the UI as the "No tools added yet" empty state). No data-migration path
is required or offered.

**Attesting user**: raftaar1191@gmail.com

**Validity window**: 2026-07-09 → Feature 020 merge.
