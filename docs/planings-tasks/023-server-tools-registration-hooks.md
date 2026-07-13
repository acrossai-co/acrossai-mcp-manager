# Planning: Wire the saved per-server tool selection into MCP server registration, and store protocol-tool enablement as three boolean columns on the server row (Feature 023)

> **Note (2026-07-14)**: The Spec-Kit feature directory for this planning doc is
> [`specs/025-server-tools-registration-hooks/`](../../specs/025-server-tools-registration-hooks/). The
> `023` prefix on this file is decoupled from the `specs/` numbering — the
> Spec-Kit registry landed on `025` when the branch was created because slots
> 023 and 024 were consumed by other features between planning and execution.
> The canonical plan is [`specs/025-server-tools-registration-hooks/plan.md`](../../specs/025-server-tools-registration-hooks/plan.md);
> tasks are in [`tasks.md`](../../specs/025-server-tools-registration-hooks/tasks.md).

Close the loop between the Tools tab UI (Feature 020) and the MCP adapter's server registration by giving every server a **hybrid** tool storage:

1. **Curated abilities** stay in the presence-based rows of `wp_acrossai_mcp_server_tools` (unchanged since Feature 020 — same schema, same `MCPServerToolQuery` API).
2. **The three MCP protocol tools** (`mcp-adapter/discover-abilities`, `mcp-adapter/get-ability-info`, `mcp-adapter/execute-ability`) become **three new boolean columns** on `wp_acrossai_mcp_servers`, each `tinyint(1) NOT NULL DEFAULT 1`. `1` = tool is enabled for that server, `0` = the operator has removed it via the Tools tab.

The DEFAULT of `1` is the whole backwards-compat mechanism: BerlinDB's `maybe_upgrade()` runs `ALTER TABLE ... ADD COLUMN ... DEFAULT 1` when the plugin's schema version bumps, and MySQL populates every pre-existing row with `1` on the ALTER. No backfill helper, no `INSERT IGNORE` gymnastics, no idempotency worries — the `ALTER` is the migration.

Today the Tools tab at `?page=acrossai_mcp_manager&action=edit&server=N&tab=tools` persists the operator's curated selection into `wp_acrossai_mcp_server_tools`, and `ToolExposureGate` enforces it at call-time via `mcp_adapter_pre_tool_call` (priority 30) — but the *registration side* never reads the selection, so `tools/list` on any server only advertises the three MCP protocol tools (hard-coded into `Controller::register_database_servers()` at `includes/MCP/Controller.php:150–154`). The current UI also treats those three as read-only "always available" ornaments locked into the right pane, and `ToolsController::EXCLUDED_SLUGS` rejects them from the save payload. Feature 023 changes both:

1. **Registration side.** `Controller::register_database_servers()` composes the final tools list from two sources per server: for each of the three `tool_*` columns on the server row, include the corresponding protocol slug if the column is `1`; then union with `MCPServerToolQuery::get_added_slugs()` for that server. The vendor's default-server path gains a filter callback on `mcp_adapter_default_server_config` that does the same composition using the seeded default row (`server_slug = mcp-adapter-default-server`). Companion plugins get a new filter — `acrossai_mcp_manager_server_tools` — for database servers.
2. **UI side.** The three protocol tools become **removable defaults** rather than immovable built-ins. They still ship with the recommended-defaults color (`#fef7e0` / `#8a6d00`) so operators recognize them at a glance, but each now has a `Remove` button. Clicking `Remove` opens a `@wordpress/components` `ConfirmDialog` that warns "This tool is required by AI clients to discover and execute WordPress abilities on this server. Removing it may prevent connected AI clients from working correctly. Are you sure you want to remove it?" — `Remove anyway` flips the corresponding column to `0`, `Cancel` keeps it. The `Reset` button in the "Added as tools" pane changes semantics: it flips all three `tool_*` columns back to `1` **and** clears every curated row from `wp_acrossai_mcp_server_tools` for that server, matching the "recommended factory defaults" mental model.

The new column-based storage is deliberately different from the row-based curated storage — the two layers represent different concepts:

| Layer | Storage | Semantics | Example query |
| --- | --- | --- | --- |
| Protocol tools | 3 `tinyint(1)` columns on `wp_acrossai_mcp_servers` | Fixed set of three known slugs; each column is an on/off flag | `SELECT tool_discover_abilities, tool_get_ability_info, tool_execute_ability FROM wp_acrossai_mcp_servers WHERE id = ?` |
| Curated abilities | Presence rows in `wp_acrossai_mcp_server_tools` | Open-ended set of arbitrary ability slugs the operator picks | `SELECT ability_slug FROM wp_acrossai_mcp_server_tools WHERE server_id = ?` |

This split has two upsides over the earlier plan iteration (protocol tools as backfilled rows):

- **No migration to write.** The `DEFAULT 1` on the ADD COLUMN is the migration. Existing installs pick up the ALTER on the next request-time BerlinDB `maybe_upgrade()`.
- **Structural clarity.** Protocol tools are a fixed, known set; representing them as columns matches their fixed cardinality. Curated abilities are open-ended; rows are correct for that.

The migration is **fully backwards-compatible**:

- `wp_acrossai_mcp_server_tools` schema, `MCPServerToolQuery` public API, `ToolsController` REST-route shape, and `ToolExposureGate` filter registration are all unchanged.
- Every server that today exposes the three protocol tools continues to expose them after the ALTER — the `DEFAULT 1` puts every existing row into the "all three enabled" state.
- Curated picks continue to be exposed exactly as before.
- New behavior only differs when an operator explicitly opens the UI and removes a protocol tool (a new user-driven action that wasn't possible before).

The current state — `includes/MCP/Controller.php::register_database_servers()` lines 140–157 — hardcodes the three protocol tools as the 10th argument to `create_server()`. The default server is created by the vendor's `\WP\MCP\Servers\DefaultServerFactory::create()` which applies the vendor filter `mcp_adapter_default_server_config` at `vendor/wordpress/mcp-adapter/includes/Servers/DefaultServerFactory.php:88` but nothing in this plugin hooks it. `ToolsController::EXCLUDED_SLUGS` at `includes/REST/ToolsController.php:69–73` rejects the three protocol tools from any POST body — Feature 023 deletes that constant and its guard, but instead of accepting protocol slugs into `wp_acrossai_mcp_server_tools` rows, the controller now routes them to the three columns on `wp_acrossai_mcp_servers`. The mirroring `EXCLUDED_SLUGS` set in `src/js/tools.js` at line 43 filters the three protocol tools out of the ability pool on the left pane and is removed for the same reason. `BUILTIN_ABILITIES` at `src/js/tools.js:56+` is kept — it's still the source of the three tools' labels, descriptions, and default color — but the per-tool rendering branch that swaps `Remove` for a lock badge is rewritten to render `Remove` in all cases.

The **new call-time invariant** (important for reviewers): if an operator explicitly removes all three protocol tools from a server **and** has no curated picks, that server's `tools/list` becomes empty and the server stops being usable by any AI client. This is by design — the confirmation dialog surfaces the risk, and `Reset` is one click away. `ToolExposureGate::EXCLUDED_SLUGS` (which currently lets protocol-tool calls bypass curation) becomes vestigial once the DB truly reflects what's registered; the gate's bypass is preserved as a belt-and-braces safety net so a client that cached the tool slug from an earlier request doesn't hit a 403 mid-conversation, but the adapter will still refuse the call since the tool isn't registered. This is documented as a known non-blocking edge and does not need dedicated code.

The design is **symmetric across the two server sources**:

- **Default server** (`registered_from = 'plugin'`, slug `mcp-adapter-default-server`): hook the vendor's `mcp_adapter_default_server_config` filter. Look up the seeded row by slug, compose `tools` = (enabled protocol columns) ∪ `get_added_slugs()`, and **replace** `$config['tools']` with that array. If the row cannot be located (unexpected — the seeder runs on activation), return the input untouched so the vendor's defaults win. No emission of a plugin-owned filter from this path — the vendor filter is the extension seam.
- **Database servers** (`registered_from = 'database'`): inside `Controller::register_database_servers()`, compose the same union from the row's columns + `get_added_slugs()`. Fire the NEW plugin-owned filter `acrossai_mcp_manager_server_tools` with signature `apply_filters( 'acrossai_mcp_manager_server_tools', string[] $tools, \AcrossAI_MCP_Manager\Includes\Database\MCPServer\Row $server ): string[]`. Callbacks can add, remove, or reorder any slug freely. Re-normalize the return before passing to `create_server()`.

The filter naming convention matches Feature 019 (`acrossai_mcp_manager_server_tabs`) — plugin-scoped prefix, singular resource, plural extension surface. The two hook seams (vendor `mcp_adapter_default_server_config` for default, plugin `acrossai_mcp_manager_server_tools` for database) match the user's stated design: "use the existing vendor filter for the default server, add a new filter for the servers we register ourselves". We do NOT emit `acrossai_mcp_manager_server_tools` for the default server, so companion plugins targeting only the default server hook one filter, companion plugins targeting only database servers hook the other, and companion plugins that want to touch every server hook both.

---

## Speckit Workflow

```markdown
# 1. Branch
/speckit.git.feature "server-tools-registration-hooks"

# 2. Specify
/speckit.specify "Give each MCP server a hybrid tool storage: three
tinyint(1) columns on wp_acrossai_mcp_servers for the protocol tools
(each DEFAULT 1, meaning enabled) plus the existing
wp_acrossai_mcp_server_tools rows for curated abilities. Wire the
composed set into \\WP\\MCP\\Core\\McpAdapter::create_server()'s 10th
argument for both server-registration paths.

Schema — includes/Database/MCPServer/Schema.php gains three columns:
tool_discover_abilities, tool_get_ability_info, tool_execute_ability —
all tinyint length 1 with 'default' => 1. Bump
includes/Database/MCPServer/Table.php's \$version from the current
'1.0.0' to '1.1.0' so BerlinDB's maybe_upgrade() runs ALTER TABLE ADD
COLUMN via dbDelta. The DEFAULT 1 on the ALTER puts every existing row
into the 'all three enabled' state — no separate backfill helper. Update
includes/Database/MCPServer/Row.php to declare the three new public int
properties with default 1 and add them to the to_array() output.

Path 1 — default server: register a filter callback on the vendor filter
'mcp_adapter_default_server_config' (declared at
vendor/wordpress/mcp-adapter/includes/Servers/DefaultServerFactory.php:88).
The callback looks up the seeded default-server row by slug
DefaultServerSeeder::SLUG ('mcp-adapter-default-server') via
MCPServerQuery::instance()->query( [ 'server_slug' => ..., 'number' =>
1 ] ), composes \$picks = MCPServerToolPolicy::compose_for_row( \$row )
(new stateless helper — union of the three columns' protocol slugs when
1, plus MCPServerToolQuery::get_added_slugs( \$row->id )), and returns
the config with \$config['tools'] REPLACED by \$picks. If the row cannot
be located OR \$picks is empty, return the input untouched so vendor
defaults win. Type-guard: if \$config is not an array or
\$config['tools'] is not an array, return the input untouched.

Path 2 — database servers: in
\\AcrossAI_MCP_Manager\\Includes\\MCP\\Controller::register_database_servers(),
for each enabled row where registered_from='database', build \$tools =
MCPServerToolPolicy::compose_for_row( \$server ). Fire the NEW plugin
filter: apply_filters( 'acrossai_mcp_manager_server_tools', \$tools,
\$server ). Re-normalize the filter's return: \$tools =
array_values( array_unique( array_map( 'strval', (array) \$tools ) ) ).
Pass this as the 10th argument to \$adapter->create_server().

Wiring: in includes/Main.php right after Controller::initialize_adapter
is added at line 513, add one more Loader call —
\$this->loader->add_filter( 'mcp_adapter_default_server_config',
\$mcp_controller, 'filter_default_server_config' ).

REST payload shape — unified: the operator's UI still POSTs a single
'tools' array to /acrossai-mcp-manager/v1/servers/{id}/tools. The
controller splits internally: any of the three protocol slugs in the
payload become tool_* = 1 on the server row (missing ones become
tool_* = 0); all other slugs are handed to
MCPServerToolQuery::replace_set() as before. GET returns the same
unified 'tools' array — protocol columns are composed back in on read.
Delete ToolsController::EXCLUDED_SLUGS and the guard in post_tools
that used it; protocol slugs are now first-class payload entries.
Delete the mirroring EXCLUDED_SLUGS set in src/js/tools.js and stop
filtering protocol tools out of the ability pool on the left pane.

UI — src/js/tools.js: keep the BUILTIN_ABILITIES constant + the
'#fef7e0' / '#8a6d00' color for the three protocol tools — they still
deserve the visual 'this is a recommended default' signal. Rewrite the
right-pane rendering so every added tool (protocol or curated) has a
Remove button. Clicking Remove on a protocol tool opens a
@wordpress/components ConfirmDialog with copy: 'This tool is required
by AI clients to discover and execute WordPress abilities on this
server. Removing it may prevent connected AI clients from working
correctly. Are you sure you want to remove it?' — Remove anyway
persists the change, Cancel keeps it. The Reset button POSTs a payload
that (a) sets all three protocol slugs in the 'tools' array and (b)
strips every non-protocol slug — the backend's split then flips all
three tool_* columns to 1 and calls replace_set() with an empty array.
Also gate Reset behind a ConfirmDialog.

Do NOT change the shape of Controller::register_database_servers()'s
outer for-loop or its use of HttpTransport / ErrorLogMcpErrorHandler /
NullMcpObservabilityHandler defaults. Do NOT touch ToolExposureGate,
AbilityExposureGate, or MCPServerToolQuery's schema/public API. Do NOT
emit acrossai_mcp_manager_server_tools from the default-server path —
the vendor filter is the extension seam there. Do NOT edit any file
under vendor/wordpress/mcp-adapter/. Do NOT write an activation
backfill — the DEFAULT 1 on the ADD COLUMN is the migration. Add
PHPUnit coverage for the composition helper, both filter paths, the
DB split in ToolsController, and the Reset semantics. Add a
filter-authors doc mirroring docs/extending-per-server-tabs.md if that
exists, else docs/extending-server-tools.md."
```

### Detailed Description for `/speckit.specify`

> **Before writing a single line of code, read and internalize all seven of
> these governing documents in full:**
>
> 1. `AGENTS.md` — this plugin's singleton pattern (every feature class
>    declares `protected static $_instance` + `public static function
>    instance(): self` + `private function __construct()`), the "hook
>    registration lives ONLY in `includes/Main.php` via `$this->loader`"
>    rule, and the Before Commit Checklist.
> 2. `docs/planings-tasks/011-berlindb-migration.md` — the canonical
>    BerlinDB schema-change pattern this plugin follows. Read
>    §"TASK-2 — MCPServer BerlinDB rewrite" plus the memory decision
>    `DEC-BERLINDB-SUBCLASS-NO-USE-COLLISION`. Feature 023's
>    schema-version bump on `MCPServer\Table` is the smallest possible
>    application of that pattern: three ADD COLUMNs, no drops, no
>    renames, no data motion.
> 3. `docs/planings-tasks/020-per-server-tool-selection.md` — established
>    the presence-based `wp_acrossai_mcp_server_tools` storage, the
>    `MCPServerToolQuery::get_added_slugs()` / `replace_set()` API, the
>    `ToolExposureGate` call-time enforcement at
>    `mcp_adapter_pre_tool_call` priority 30, and the `EXCLUDED_SLUGS`
>    exclusion list in both `ToolsController` and `src/js/tools.js`.
>    Feature 023 SUPERSEDES the "protocol tools are always-available
>    ornaments" contract from Feature 020: they become first-class
>    columns on the server row. Update the F020 memory entry to mark it
>    "supplemented by Feature 023, protocol-tool exclusion removed, new
>    column storage".
> 4. `docs/planings-tasks/019-per-server-tabs-filter.md` — established
>    the `acrossai_mcp_manager_*` filter naming convention, the
>    two-argument filter shape (`$value`, `$server`), and the "third
>    parties can add and remove but the input already contains the
>    built-ins" pattern that Feature 023 mirrors for tools.
> 5. `docs/planings-tasks/017-per-server-ability-selection.md` —
>    established the parallel storage layer
>    `wp_acrossai_mcp_server_abilities` and the `AbilityExposureGate` at
>    `mcp_adapter_pre_tool_call` priority 20. Feature 023 does NOT touch
>    this layer; the ability-visibility gate remains authoritative at
>    call-time and is orthogonal to the tool-set injection at
>    registration-time. A slug that survives the tool filter here but is
>    not exposed under Feature 017 will still be gated by
>    `AbilityExposureGate` at call-time — the two-layer contract from
>    017/020 is intact.
> 6. `vendor/wordpress/mcp-adapter/includes/Servers/DefaultServerFactory.php`
>    (lines 36–128 in the currently-vendored release) — the canonical
>    `mcp_adapter_default_server_config` producer. Read `create()`,
>    `wp_parse_args` merge behavior at line 94, and the filter docblock
>    at lines 62–87 so the callback's return contract is understood.
> 7. `vendor/wordpress/mcp-adapter/includes/Core/McpAdapter.php` (lines
>    169–303) — the `create_server()` signature. The 10th positional
>    argument is `array $tools = array()`, forwarded to
>    `McpServer::__construct()` and then to
>    `component_registry->register_tools()` which resolves each slug via
>    `wp_get_ability()`. A slug that is not a registered ability is
>    silently dropped by the adapter's own resolver, so passing an
>    unknown slug is safe but wasteful — the filter caller is
>    responsible for their own slug hygiene.
>
> Every decision — whether to store protocol tools as columns or rows,
> whether the UI POSTs a unified array or separate protocol/curated
> arrays, whether the backend unions with protocol slugs or trusts the
> DB, whether the Remove-protocol confirmation is a native `confirm()`
> or `@wordpress/components` `ConfirmDialog` — must be justified
> against the above. Defaults (confirmed with user 2026-07-13 across
> three rounds of plan clarification):
>
> - **Protocol tools are three `tinyint(1)` columns on
>   `wp_acrossai_mcp_servers`**, one per slug, `DEFAULT 1`. Curated
>   abilities stay as presence rows in `wp_acrossai_mcp_server_tools`.
>   The two storage layers are deliberately different — the protocol
>   set is fixed and known; the curated set is open-ended.
> - **The `ALTER TABLE ... ADD COLUMN ... DEFAULT 1` is the migration.**
>   No backfill helper, no activation hook step. MySQL sets `1` on
>   every existing row as part of the ALTER, which BerlinDB triggers
>   through `maybe_upgrade()` on the next request-time boot after the
>   `$version` bump.
> - **REST payload stays unified.** The Tools tab still POSTs a
>   single `tools` array — no protocol/curated split at the wire. The
>   controller splits internally: protocol slugs update the three
>   columns, everything else goes through `replace_set()`. Reading
>   composes the two sources back into a single response array. This
>   keeps the client-side model simple and preserves the current
>   response shape for any third-party API consumer.
> - **Default server uses ONLY the vendor filter** for the config seam;
>   database servers use ONLY the plugin filter. Two paths, two hooks,
>   no double-firing.
> - **Type-guard, don't try/catch.** WordPress core filters do not
>   wrap `apply_filters()` in try/catch; do the same here. Re-normalize
>   the filter's return so a filter that returns non-array / non-string /
>   duplicates cannot corrupt the `create_server()` call.
> - **Remove-protocol confirmation uses `@wordpress/components`
>   `ConfirmDialog`** (already a dependency of the Tools tab per F020)
>   — matches the WPDS pattern this plugin follows, avoids the native
>   `confirm()` UX inconsistency across browsers, and keeps the modal's
>   copy fully i18n-able via `__()`.
>
> **Public API surfaces preserved (grep-gate before + after — no surviving
> consumer permitted to change):**
>
> - `\AcrossAI_MCP_Manager\Includes\MCP\Controller::instance()`
> - `\AcrossAI_MCP_Manager\Includes\MCP\Controller::initialize_adapter()`
> - `\AcrossAI_MCP_Manager\Includes\MCP\Controller::register_database_servers( \WP\MCP\Core\McpAdapter $adapter ): void` — signature unchanged.
> - `\AcrossAI_MCP_Manager\Includes\MCP\Controller::get_adapter_status(): string` — signature unchanged.
> - `\AcrossAI_MCP_Manager\Includes\Database\MCPServerTool\Query::get_added_slugs( int ): string[]`
> - `\AcrossAI_MCP_Manager\Includes\Database\MCPServerTool\Query::replace_set( int, string[] ): array`
> - `\AcrossAI_MCP_Manager\Includes\Database\MCPServer\DefaultServerSeeder::SLUG`
> - `\AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query::instance()`
> - `POST /acrossai-mcp-manager/v1/servers/{id}/tools` — request shape
>   unchanged; only the acceptance rule changes (protocol slugs are now
>   valid entries). Response shape unchanged.
> - `GET /acrossai-mcp-manager/v1/servers/{id}/tools?include_abilities=1`
>   — response shape unchanged; `tools` array now composes protocol
>   columns + curated rows, and the `abilities` catalog includes the
>   three protocol tools as regular entries (they used to be silently
>   filtered out by `EXCLUDED_SLUGS`).
>
> **Public API surfaces added:**
>
> - `\AcrossAI_MCP_Manager\Includes\MCP\Controller::filter_default_server_config( array $config ): array` — new public method, callback for `mcp_adapter_default_server_config`.
> - `acrossai_mcp_manager_server_tools` — new WordPress filter.
> - Three new columns on `wp_acrossai_mcp_servers`:
>   `tool_discover_abilities`, `tool_get_ability_info`,
>   `tool_execute_ability`, each `tinyint(1) NOT NULL DEFAULT 1`.
> - Three new public int properties on
>   `\AcrossAI_MCP_Manager\Includes\Database\MCPServer\Row` with the
>   same names, default `1`.
> - `\AcrossAI_MCP_Manager\Includes\Database\MCPServer\ToolPolicy::compose_for_row( \AcrossAI_MCP_Manager\Includes\Database\MCPServer\Row $row ): string[]` — stateless helper (new file, no singleton) that returns the union of enabled protocol slugs + curated slugs for a server row. Used by both registration paths and the REST GET handler.
>
> **Runtime contract with third-party callers of `acrossai_mcp_manager_server_tools`:**
>
> - Fired exactly once per database server per `mcp_adapter_init` action
>   (i.e. once per REST request that reaches the MCP adapter after at
>   least one database server row is enabled).
> - NOT fired for the default server — companion plugins targeting the
>   default server hook `mcp_adapter_default_server_config` instead.
> - The first argument is `ToolPolicy::compose_for_row( $server )` —
>   the enabled protocol columns' slugs plus the curated
>   `get_added_slugs()` entries, deduped and `array_values()`-ed.
> - The second argument is the `MCPServer\Row` object being registered.
>   Callbacks can gate on `$server->id`, `$server->server_slug`,
>   `$server->tool_discover_abilities`, etc.
> - The return value MUST be an array (or coercible to one). Non-array
>   returns are wrapped with `(array)`, non-string entries are
>   `strval`'d, duplicates are collapsed. A `null` or `false` return
>   degrades to `[]`.
> - Callbacks MAY add or remove protocol tools freely — the filter
>   sees a single unified array without any column/row distinction.
> - A `\Throwable` from a callback propagates. Standard WordPress
>   behavior.
>
> ---
>
> **TASK-1 — Schema change: add three columns, bump table version.**
>
> Files:
> - `includes/Database/MCPServer/Schema.php` (add three columns)
> - `includes/Database/MCPServer/Table.php` (bump `$version` `1.0.0` →
>   `1.1.0`)
> - `includes/Database/MCPServer/Row.php` (three new public properties
>   + `to_array()` entries)
>
> `Schema.php` — append three entries to `$columns` (order after the
> existing `server_version` column, before `created_at` so DDL stays
> readable):
>
> ```php
> array(
>     'name'    => 'tool_discover_abilities',
>     'type'    => 'tinyint',
>     'length'  => '1',
>     'default' => 1,
> ),
> array(
>     'name'    => 'tool_get_ability_info',
>     'type'    => 'tinyint',
>     'length'  => '1',
>     'default' => 1,
> ),
> array(
>     'name'    => 'tool_execute_ability',
>     'type'    => 'tinyint',
>     'length'  => '1',
>     'default' => 1,
> ),
> ```
>
> `Table.php` — bump `protected $version = '1.0.0';` → `'1.1.0';`.
> This is the only trigger BerlinDB's `maybe_upgrade()` needs to run
> ALTER TABLE via dbDelta. The `DEFAULT 1` on the ALTER puts every
> existing row into the "all three enabled" state as MySQL fills the
> new columns during the ALTER's schema update.
>
> `Row.php` — declare three new public properties:
>
> ```php
> /** @var int */ public $tool_discover_abilities = 1;
> /** @var int */ public $tool_get_ability_info   = 1;
> /** @var int */ public $tool_execute_ability    = 1;
> ```
>
> Update the constructor to cast each to `int` (defensive — DB layer
> returns strings for `tinyint`):
>
> ```php
> $this->tool_discover_abilities = (int) $this->tool_discover_abilities;
> $this->tool_get_ability_info   = (int) $this->tool_get_ability_info;
> $this->tool_execute_ability    = (int) $this->tool_execute_ability;
> ```
>
> Update `to_array()` to include the three new keys.
>
> Post-edit verification: bump the plugin's own version or reactivate;
> observe on the next request-time boot that
> `wp_acrossai_mcp_servers_db_version` option equals `1.1.0` and
> `SHOW COLUMNS FROM wp_acrossai_mcp_servers LIKE 'tool_%'` returns
> exactly three rows.
>
> ---
>
> **TASK-2 — `ToolPolicy::compose_for_row()` helper.**
>
> New file: `includes/Database/MCPServer/ToolPolicy.php`
>
> Stateless helper (no singleton, no ctor state) — mirrors the shape of
> `DefaultServerSeeder`. Owns:
>
> - `public const PROTOCOL_TOOLS` — the three protocol slugs (single
>   canonical PHP source; the JS mirror in `src/js/tools.js` is a
>   build-time constant kept in step by hand).
> - `public const COLUMN_MAP` — `[ 'tool_discover_abilities' =>
>   'mcp-adapter/discover-abilities', 'tool_get_ability_info' =>
>   'mcp-adapter/get-ability-info', 'tool_execute_ability' =>
>   'mcp-adapter/execute-ability' ]`. Used by the REST controller to
>   translate slugs ↔ columns.
> - `public static function compose_for_row( Row $row ): array` —
>   returns the union of enabled protocol slugs + curated slugs for the
>   server. Reads `$row->tool_discover_abilities` etc. for the columns
>   and calls `MCPServerToolQuery::instance()->get_added_slugs( (int)
>   $row->id )` for the curated portion. Returns a `array_values(
>   array_unique( ... ) )`-normalized list.
> - `public static function split_payload( array $tools ): array` —
>   given a POST body's `tools` array, returns `[ 'columns' =>
>   [ 'tool_discover_abilities' => 0|1, ... ], 'curated' => [ slugs
>   without protocol entries ] ]`. Used by `ToolsController::post_tools()`.
>
> Cover with a unit test file per TASK-9.
>
> ---
>
> **TASK-3 — Rewrite `Controller::register_database_servers()` to compose from DB + fire the new filter.**
>
> File: `includes/MCP/Controller.php`
>
> Add `use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ToolPolicy;` alongside the existing MCPServer\Query import.
>
> Delete the inlined 3-element array literal at lines 150–154.
>
> Inside the `foreach ( $servers as $server )` body, immediately before
> the `$adapter->create_server(...)` call, add:
>
> ```php
> $tools = ToolPolicy::compose_for_row( $server );
>
> /**
>  * Filter the tools list a plugin-registered (database) MCP server exposes.
>  *
>  * Fired inside Controller::register_database_servers() per server,
>  * immediately before $adapter->create_server(). The initial list is
>  * the union of the row's enabled tool_* columns (protocol slugs) and
>  * the ability slugs saved in wp_acrossai_mcp_server_tools for this
>  * server_id. Callbacks may add or remove any slug freely.
>  *
>  * NOT fired for the default server (server_slug =
>  * 'mcp-adapter-default-server'). Hook `mcp_adapter_default_server_config`
>  * for that path.
>  *
>  * @since 0.0.1 (Feature 023)
>  *
>  * @param string[]                                                                    $tools  Ability slugs to register.
>  * @param \AcrossAI_MCP_Manager\Includes\Database\MCPServer\Row                       $server The server row being registered.
>  */
> $tools = apply_filters( 'acrossai_mcp_manager_server_tools', $tools, $server );
> $tools = array_values( array_unique( array_map( 'strval', (array) $tools ) ) );
> ```
>
> Replace the 10th argument to `$adapter->create_server()` with
> `$tools`. Do not touch the other arguments. Do not touch the
> `_doing_it_wrong` error branch.
>
> Grep after edit:
> ```
> grep -n "mcp-adapter/discover-abilities\|mcp-adapter/get-ability-info\|mcp-adapter/execute-ability" includes/MCP/Controller.php
> ```
> Expected: **zero matches** inside `Controller.php` — protocol slugs
> now live only inside `ToolPolicy::PROTOCOL_TOOLS`.
>
> ---
>
> **TASK-4 — Add `Controller::filter_default_server_config()` method.**
>
> File: `includes/MCP/Controller.php`
>
> Add `use AcrossAI_MCP_Manager\Includes\Database\MCPServer\DefaultServerSeeder;` to the class imports if not already present.
>
> Append the following public method to the class (placement:
> immediately after `register_database_servers()`, before
> `get_adapter_status()`):
>
> ```php
> /**
>  * Callback for the vendor filter `mcp_adapter_default_server_config`.
>  *
>  * REPLACES $config['tools'] with the composed slug set for the
>  * seeded default server row — the enabled `tool_*` columns plus
>  * whatever the operator saved in wp_acrossai_mcp_server_tools. The
>  * schema DEFAULT 1 on the ALTER (Feature 023 TASK-1) ensures a
>  * fresh install exposes all three protocol tools out of the box.
>  *
>  * Wired via Loader in `Includes\Main::define_admin_hooks()`. Called
>  * once per `mcp_adapter_init` firing.
>  *
>  * Defensive: returns the input untouched if
>  *  - the config is not an array,
>  *  - $config['tools'] is not an array,
>  *  - the default server row cannot be located by slug (unseeded
>  *    install — unexpected),
>  *  - the composed picks array is empty (the operator explicitly
>  *    removed every tool AND has no curated picks AND declined
>  *    Reset — vendor defaults are the safer fallback).
>  *
>  * Does NOT fire `acrossai_mcp_manager_server_tools`.
>  *
>  * @since 0.0.1 (Feature 023)
>  *
>  * @param mixed $config The vendor-supplied config array.
>  * @return array The config with the composed slug set replacing `tools`.
>  */
> public function filter_default_server_config( $config ) {
>     if ( ! is_array( $config ) || ! isset( $config['tools'] ) || ! is_array( $config['tools'] ) ) {
>         return $config;
>     }
>
>     $rows = MCPServerQuery::instance()->query(
>         array(
>             'server_slug' => DefaultServerSeeder::SLUG,
>             'number'      => 1,
>         )
>     );
>     if ( empty( $rows ) ) {
>         return $config;
>     }
>
>     $tools = ToolPolicy::compose_for_row( $rows[0] );
>     if ( empty( $tools ) ) {
>         return $config;
>     }
>
>     $config['tools'] = $tools;
>     return $config;
> }
> ```
>
> ---
>
> **TASK-5 — Wire the vendor filter in `Main.php`.**
>
> File: `includes/Main.php`
>
> Immediately after the existing line (currently line 513):
> ```php
> $this->loader->add_action( 'rest_api_init', $mcp_controller, 'initialize_adapter' );
> ```
> add ONE line:
> ```php
> $this->loader->add_filter( 'mcp_adapter_default_server_config', $mcp_controller, 'filter_default_server_config' );
> ```
>
> No other changes to `Main.php`.
>
> ---
>
> **TASK-6 — `ToolsController` split payload on POST, compose on GET.**
>
> File: `includes/REST/ToolsController.php`
>
> - Delete the `EXCLUDED_SLUGS` constant at lines 69–73.
> - Delete the validation branch in `post_tools()` (~lines 262–271)
>   that rejects submissions containing any of those three slugs.
> - In `post_tools()`, after validation and before calling
>   `MCPServerToolQuery::replace_set()`, split the payload via
>   `ToolPolicy::split_payload( $tools_param )`:
>   - Persist the `columns` half to `wp_acrossai_mcp_servers` via
>     `MCPServerQuery::instance()->update_item( $server_id, $columns )`
>     — updates all three columns in one query (missing keys default
>     to `0`, i.e. the caller removed that protocol tool).
>   - Persist the `curated` half via
>     `MCPServerToolQuery::instance()->replace_set( $server_id,
>     $curated )` — existing transactional path.
> - In `get_tools()`, after fetching the server row, compose the
>   response's `tools` array via `ToolPolicy::compose_for_row( $row )`
>   instead of calling `get_added_slugs()` directly. The `abilities`
>   catalog (when `include_abilities=1`) now includes the three
>   protocol tools as regular entries — Feature 020's
>   `EXCLUDED_SLUGS`-based filter is gone.
> - The transactional-serialization guarantee that `replace_set()`
>   provides today does NOT extend across the two writes (column
>   update + row replace). Document this in a code comment as a known
>   accepted race — two concurrent saves on the same server may leave
>   the columns from writer A and the curated rows from writer B. In
>   practice the Tools tab is single-operator; the window is
>   milliseconds. If this becomes a real problem in the future, wrap
>   both writes in an explicit `START TRANSACTION` at the controller
>   layer; that's out of scope for Feature 023.
>
> Grep after edit:
> ```
> grep -n "EXCLUDED_SLUGS\|discover-abilities" includes/REST/ToolsController.php
> ```
> Expected: **zero matches**.
>
> ---
>
> **TASK-7 — Rewrite `src/js/tools.js` UI to make protocol tools removable.**
>
> File: `src/js/tools.js`
>
> Changes:
>
> 1. Delete the `EXCLUDED_SLUGS` constant (~lines 43–48) and every call
>    to `EXCLUDED_SLUGS.has(...)` in the pool-filtering logic
>    (~line 323). The three protocol tools now appear in the left
>    pane's ability pool like any other tool.
> 2. Keep `BUILTIN_ABILITIES` (~line 56+) — it's still the label +
>    description source for the three protocol tools when the ability
>    registry doesn't carry them as WordPress abilities.
> 3. Keep the `'Built-in'` color entry `{ bg: '#fef7e0', fg: '#8a6d00' }`
>    at line 94 — visual signal for the three protocol tools no matter
>    which pane they appear in.
> 4. Rewrite the right-pane render branch (~line 660) so protocol tools
>    ALWAYS render a `Remove` button. Remove the `<Lock>` icon branch
>    and the "Built-in — always available on every server." tooltip.
>    The `#fef7e0` background stays.
> 5. Add a `ConfirmDialog` from `@wordpress/components` gated on
>    protocol-slug removal. Trigger: `Remove` click on any entry whose
>    slug is one of the three. Copy: "This tool is required by AI
>    clients to discover and execute WordPress abilities on this
>    server. Removing it may prevent connected AI clients from working
>    correctly. Are you sure you want to remove it?". Confirm button:
>    "Remove anyway"; Cancel: "Cancel". `Confirm` posts the diff to
>    the REST endpoint; `Cancel` closes the dialog.
> 6. Non-protocol `Remove` clicks bypass the dialog and remove
>    immediately, matching current behavior.
> 7. Rewrite the `Reset` button (~line 617). Behavior:
>    - Open a second `ConfirmDialog` with copy "Reset the tools for
>      this server to only the three built-in defaults? All curated
>      picks will be removed." and confirm text "Reset to defaults".
>    - On confirm, POST
>      `{ tools: [ 'mcp-adapter/discover-abilities',
>      'mcp-adapter/get-ability-info',
>      'mcp-adapter/execute-ability' ] }` — the backend's split then
>      flips all three columns to `1` and calls `replace_set()` with
>      an empty array, which drops every non-protocol row.
> 8. Update the count text at line 455–457 to include protocol tools
>    in the added count (they're now real DB state). New text:
>    `'%1$d of %2$d abilities added as tools'` (drop the `· %3$d
>    built-in always available` suffix).
> 9. Update the empty-state copy at line 738 to remove the mention of
>    "built-in protocol tools shown above" — the UI reflects DB state.
>
> ---
>
> **TASK-8 — PHPUnit coverage.**
>
> New file: `tests/phpunit/Database/MCPServer/ToolPolicyTest.php`
>
> Test cases:
>
> 1. `compose_for_row` on a row with all three columns `1` and 0
>    curated → returns the three protocol slugs in order.
> 2. `compose_for_row` on a row with `tool_execute_ability = 0` → the
>    execute slug is absent.
> 3. `compose_for_row` on a row with all three columns `0` and 0
>    curated → returns `[]`.
> 4. `compose_for_row` with curated picks → curated slugs appear in
>    the response after the protocol slugs.
> 5. `compose_for_row` dedupes when a curated pick's slug matches a
>    protocol slug (edge case — the column split in POST should
>    prevent this, but defense-in-depth for direct DB writers).
> 6. `split_payload` with three protocol slugs + two curated →
>    columns all `1`, curated array has the two.
> 7. `split_payload` with zero protocol slugs and three curated →
>    all three columns `0`, curated has the three.
> 8. `split_payload` on empty array → all three columns `0`, curated
>    is `[]`.
>
> New file: `tests/phpunit/MCP/ControllerToolsInjectionTest.php`
>
> Test cases:
>
> 1. `filter_default_server_config` returns input untouched when the
>    row is missing.
> 2. `filter_default_server_config` returns input untouched when
>    `compose_for_row` returns `[]`.
> 3. `filter_default_server_config` REPLACES `$config['tools']` with
>    the composed set and preserves every other config key.
> 4. `filter_default_server_config` returns input untouched when
>    `$config['tools']` is not an array.
> 5. `filter_default_server_config` returns input untouched when
>    `$config` is not an array.
> 6. `register_database_servers` passes exactly
>    `ToolPolicy::compose_for_row( $row )` as the 10th argument.
> 7. `register_database_servers` fires
>    `acrossai_mcp_manager_server_tools` exactly once per server with
>    the correct `(array, MCPServer\Row)` shape.
> 8. `register_database_servers` respects the filter's return —
>    remove-a-protocol-tool test, add-a-slug test, return-null
>    degrades to `[]`.
>
> Update existing `tests/phpunit/REST/ToolsControllerTest.php`
> (if present) to:
> - Remove the assertion that POSTing protocol slugs returns 400.
> - Add an assertion that POSTing `[protocol, protocol, curated]`
>   updates the three columns to `1` AND `replace_set`s the curated
>   pick.
> - Add an assertion that POSTing `[curated_only]` updates the three
>   columns to `0`.
> - Add an assertion that GET returns a `tools` array that is the
>   composed union.
>
> New PHPUnit case for schema migration: assert that after
> `MCPServerTable::instance()->maybe_upgrade()` on an old
> `$version=1.0.0` snapshot, the three columns exist and every
> pre-existing row has all three columns equal to `1`.
>
> ---
>
> **TASK-9 — Extension author documentation.**
>
> New file: `docs/extending-server-tools.md`
>
> Shape and tone mirror `docs/extending-per-server-tabs.md` if that
> exists. Sections:
>
> 1. **Storage model** — one paragraph on the column/row split:
>    protocol tools live in `wp_acrossai_mcp_servers` columns; curated
>    live in `wp_acrossai_mcp_server_tools` rows. The filter sees the
>    composed union without the split.
> 2. **Filter contract** — signature, when it fires, when it does NOT
>    fire (default server), what the two arguments are, what the
>    return contract is.
> 3. **Two-hook model** — table showing which hook to use for which
>    server source, with the vendor filter cross-linked.
> 4. **Worked example 1** — add a "Notes" ability slug to every
>    database server named "Marketing".
> 5. **Worked example 2** — remove `mcp-adapter/execute-ability` from
>    read-only-audit servers.
> 6. **Worked example 3** — same via
>    `mcp_adapter_default_server_config` for the default server.
> 7. **Throw safety note** — WP filter default behavior.
>
> ---
>
> **TASK-10 — Verify.**
>
> - `composer phpcs` — zero errors on new/modified files.
> - `composer phpstan` — level 8, zero errors, no new baseline
>   entries.
> - `composer test` — new tests green.
> - `npm run lint:js` — zero errors on `src/js/tools.js`.
> - `npm run build` — clean production build.
> - Grep audit:
>   ```
>   grep -n "apply_filters( 'acrossai_mcp_manager_server_tools'" includes/
>   ```
>   Expected: exactly one match (inside `register_database_servers`).
>   ```
>   grep -n "mcp_adapter_default_server_config" includes/
>   ```
>   Expected: exactly one hit inside `Main.php` (the `add_filter`).
>   ```
>   grep -rn "EXCLUDED_SLUGS" includes/ src/js/
>   ```
>   Expected: **zero matches**.
>   ```
>   grep -rn "mcp-adapter/discover-abilities" includes/
>   ```
>   Expected: exactly one match, inside `ToolPolicy::PROTOCOL_TOOLS`.
> - Manual E2E — see the Manual Verification Checklist below.
>
> ---
>
> **CONSTRAINTS (violations = defect):**
>
> - MUST NOT modify any file under `vendor/wordpress/mcp-adapter/`.
> - MUST NOT modify `ToolExposureGate` or `AbilityExposureGate`.
>   Enforcement layers stay unchanged. `ToolExposureGate::EXCLUDED_SLUGS`
>   becomes vestigial but is preserved as a safety net for cached
>   clients.
> - MUST NOT modify the `wp_acrossai_mcp_server_tools` schema or
>   `MCPServerToolQuery`'s public API.
> - MUST NOT write a separate backfill helper. The `ALTER TABLE ...
>   ADD COLUMN ... DEFAULT 1` is the migration.
> - MUST NOT emit `acrossai_mcp_manager_server_tools` from
>   `filter_default_server_config()` — the vendor filter is the
>   extension seam for the default server. Emitting from both paths
>   would double-fire on any request that hits both servers.
> - MUST NOT wrap `apply_filters( 'acrossai_mcp_manager_server_tools' )`
>   in try/catch. Standard WordPress filter behavior applies.
> - MUST re-normalize the filter return (`array_values( array_unique(
>   array_map( 'strval', (array) $tools ) ) )`) so a defensive
>   normalize sits between third-party code and `create_server()`.
> - MUST NOT change the return type, signature, or error branch of
>   `register_database_servers()`.
> - MUST NOT change the signature, request shape, or response shape of
>   the two REST endpoints. Only the acceptance rule (`EXCLUDED_SLUGS`
>   guard) is deleted from POST validation, and the GET response's
>   `tools` array now composes from both storage layers.
> - MUST use `@wordpress/components` `ConfirmDialog` for the
>   remove-protocol and reset dialogs, not native `confirm()`.
> - MUST use `ToolPolicy::PROTOCOL_TOOLS` as the single canonical PHP
>   source for the three slugs. Grep after implementation:
>   `grep -rn "mcp-adapter/discover-abilities" includes/` returns
>   exactly one match, inside `ToolPolicy`.
```

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
npm run lint:js
npm run build

# 6. Review + memory + commit
/speckit.analyze
/speckit.architecture-guard.architecture-review
/speckit.security-review.staged
/speckit.memory-md.capture-from-diff
/speckit.git.commit
```

---

## Manual Verification Checklist

### TASK-1 — Schema migration
- [ ] After activation (or a request-time boot after the plugin's
      version bumps), `wp option get acrossai_mcp_servers_db_version`
      returns `1.1.0`.
- [ ] `wp db query "SHOW COLUMNS FROM wp_acrossai_mcp_servers LIKE
      'tool_%'"` returns exactly three rows —
      `tool_discover_abilities`, `tool_get_ability_info`,
      `tool_execute_ability` — each `tinyint(1) NOT NULL DEFAULT 1`.
- [ ] On an install that had N server rows before the ALTER,
      `SELECT COUNT(*) FROM wp_acrossai_mcp_servers WHERE
      tool_discover_abilities = 1 AND tool_get_ability_info = 1 AND
      tool_execute_ability = 1` returns `N` (backfill via DEFAULT).
- [ ] Re-running `MCPServerTable::instance()->maybe_upgrade()` is a
      no-op after the first successful ALTER.

### TASK-2 — `ToolPolicy` helper
- [ ] `\AcrossAI_MCP_Manager\Includes\Database\MCPServer\ToolPolicy` is
      autoloaded and its unit test file passes.
- [ ] `ToolPolicy::PROTOCOL_TOOLS` and `ToolPolicy::COLUMN_MAP` are the
      only PHP-side canonical sources of the three slugs.
- [ ] `grep -c "mcp-adapter/discover-abilities" includes/` returns
      `1`.

### TASK-3 — DB-authoritative `register_database_servers()`
- [ ] Enable a database server, pick 2 curated abilities, Save.
- [ ] `curl` the server's endpoint with a `tools/list` JSON-RPC
      request — the response includes both the three protocol tools
      AND the two curated picks.
- [ ] Remove one protocol tool via the UI (confirm the dialog).
      Re-issue `tools/list`; the removed tool is gone.
- [ ] Add a temporary
      `add_filter( 'acrossai_mcp_manager_server_tools',
      fn( $t, $s ) => array_values( array_diff( $t,
      [ 'mcp-adapter/execute-ability' ] ) ), 10, 2 );`
      to a mu-plugin; re-issue `tools/list`; `execute-ability` is
      gone even if the column is `1`. Remove the mu-plugin filter.

### TASK-4 — `filter_default_server_config()`
- [ ] Enable the default server; pick 2 abilities; Save.
- [ ] `curl` the default server's endpoint (`tools/list`); the
      response includes the three protocol tools + the two picks.
- [ ] Manually flip one column: `wp db query "UPDATE
      wp_acrossai_mcp_servers SET tool_get_ability_info = 0 WHERE
      server_slug = 'mcp-adapter-default-server'"`. Re-issue
      `tools/list`; `get-ability-info` is gone.
- [ ] Delete the seeded default row entirely; re-issue `tools/list`;
      the response contains the vendor's default tools (fallback).
      Re-seed via reactivation.

### TASK-5 — Loader wiring
- [ ] `grep -n "mcp_adapter_default_server_config" includes/Main.php`
      returns exactly one line (the `add_filter`).

### TASK-6 — REST split + compose
- [ ] `POST /acrossai-mcp-manager/v1/servers/{id}/tools` with a body
      of `{ "tools": [ "mcp-adapter/discover-abilities",
      "some-plugin/some-ability" ] }` returns 200 and:
      - `SELECT tool_discover_abilities, tool_get_ability_info,
        tool_execute_ability FROM wp_acrossai_mcp_servers WHERE id =
        {id}` returns `(1, 0, 0)`.
      - `SELECT ability_slug FROM wp_acrossai_mcp_server_tools WHERE
        server_id = {id}` returns exactly `some-plugin/some-ability`.
- [ ] `GET /acrossai-mcp-manager/v1/servers/{id}/tools` returns
      `{ "tools": [ "mcp-adapter/discover-abilities",
      "some-plugin/some-ability" ] }`.
- [ ] The old 400 response ("protocol tools not allowed") is gone.

### TASK-7 — Tools tab UI
- [ ] Load the Tools tab for any enabled server.
- [ ] The three protocol tools appear in the "Added as tools" pane
      with the `#fef7e0` background AND a `Remove` button (no lock
      icon).
- [ ] Clicking `Remove` on `Discover Abilities` opens a
      `ConfirmDialog` with the copy from TASK-7. `Cancel` closes the
      dialog; the row is unchanged. `Remove anyway` posts the diff;
      the row disappears; the column value in DB flips to `0`; the
      count text decrements.
- [ ] After removal, `Discover Abilities` reappears in the left
      pane's "All abilities" list with the `#fef7e0` badge and a
      `+ Add` button. Clicking `+ Add` re-adds it (no confirmation —
      adding is low-risk), and the column flips back to `1`.
- [ ] Clicking `Reset` opens a `ConfirmDialog`; `Reset to defaults`
      POSTs the payload. After the POST, all three columns are `1`,
      the curated table for this server has zero rows, and the UI
      shows only the three protocol tools.
- [ ] Non-protocol `Remove` clicks bypass the `ConfirmDialog` and
      remove immediately.

### TASK-8 — PHPUnit
- [ ] `vendor/bin/phpunit tests/phpunit/Database/MCPServer/ToolPolicyTest.php`
      — all cases pass.
- [ ] `vendor/bin/phpunit tests/phpunit/MCP/ControllerToolsInjectionTest.php`
      — all cases pass.
- [ ] `vendor/bin/phpunit tests/phpunit/REST/ToolsControllerTest.php`
      — the updated column-split and compose-on-read assertions
      pass; the old rejection assertion is removed.
- [ ] Schema migration test asserts that after
      `maybe_upgrade()` on the pre-F023 `$version=1.0.0` snapshot,
      the three columns exist and every pre-existing row has all
      three columns equal to `1`.

### TASK-9 — Docs
- [ ] `docs/extending-server-tools.md` exists with all seven
      sections.
- [ ] `docs/planings-tasks/README.md` (or equivalent index) lists
      `023-server-tools-registration-hooks.md`.

### TASK-10 — Quality gates
- [ ] `composer phpcs` — zero errors on the plugin.
- [ ] `composer phpstan` — zero errors, no new baseline.
- [ ] `composer test` — full suite green.
- [ ] `npm run lint:js` — clean.
- [ ] `npm run build` — clean.

### End-to-end regression (blocker before merge)
- [ ] Call-time gate still enforces: with a curated ability,
      `tools/call` succeeds; with an ability the operator has NOT
      added, the 403 `acrossai_mcp_tool_not_added` still fires
      (Feature 020 behavior unchanged).
- [ ] A fresh install (drop `wp_acrossai_mcp_server_tools`, delete
      every server row, reactivate the plugin, enable the default
      server) reports the three protocol tools in `tools/list` — no
      error, no warning, no fatal.
- [ ] Grep audit from TASK-10 all pass.

---

## Pre-flight Attestation

**Captured**: 2026-07-13 during Plan-mode conversation with user across
three clarification rounds (protocol-tool filter policy, UI-removability,
and columns-vs-rows storage).

**Attestation**: Feature 023 adds three `tinyint(1) NOT NULL DEFAULT 1`
columns to `wp_acrossai_mcp_servers` via BerlinDB's `maybe_upgrade()`
after bumping `MCPServer\Table::$version` from `1.0.0` to `1.1.0`. The
`DEFAULT 1` on the ALTER is the migration — every pre-existing row is
populated with `1` on the schema update, preserving the pre-Feature-023
"protocol tools always exposed" behavior for every install. Public API
surfaces (Query classes, REST route shapes, filter names) are
preserved. The plugin is dev/local per Feature 011's pre-flight
attestation; no additional attestation required.

**Attesting user**: raftaar1191@gmail.com

**Validity window**: 2026-07-13 → Feature 023 merge.
