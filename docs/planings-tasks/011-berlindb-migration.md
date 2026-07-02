# Planning: Migrate DB modules to BerlinDB Core (Feature 011)

Migrate the four custom-`dbDelta` DB modules under `includes/Database/` — `MCPServer`,
`CliAuthLog`, `OAuthToken`, `OAuthAudit` — to extend BerlinDB Core 3.0
(`\BerlinDB\Database\Kern\{Table, Schema, Query, Row}`), matching the pattern
already in use by the sibling plugin `acrossai-abilities-manager`
(Feature 038-onward). The composer dependency `berlindb/core: ^3.0.0` is already
installed via Feature 010 but no module currently consumes it. This feature
retires the hand-rolled Table/Schema/Query/Row layer, closes an observed
missing-table activation bug for `wp_acrossai_mcp_servers`, and unifies the DB
layer across the two AcrossAI plugins.

The upstream hooks the current Query classes consume — `dbDelta`,
`register_activation_hook`, `$wpdb` — remain available for BerlinDB's install
lifecycle exactly as before. Nothing about this feature's scope prevents
external consumers of the Query classes from continuing to call
`->query( [...] )`, `->add_item( [...] )`, `->update_item( $id, [...] )`,
`->delete_item( $id )` at unchanged signatures. Bespoke methods
(`redeem_atomic()`, `delete_expired_oauth_codes()`, `delete_older_than()`,
OAuthToken's `active_only` filter) carry over verbatim.

The migration is **backwards-compatible with existing data**: table names and
`db_version_key` option names are preserved byte-for-byte, and each Schema's
`$columns` + `$indexes` MUST reproduce the current `CREATE TABLE` statement
exactly so BerlinDB's diff engine treats healthy installs as "no upgrade
needed". The one deliberate behavioral change is the addition of the
sibling plugin's **phantom-version guard** (`if ( ! $this->exists() )
{ delete_option( $this->db_version_key ); } parent::maybe_upgrade();`) on every
Table subclass — this fixes the observed bug where `wp_acrossai_mcp_servers`
never appears on activation because the version option gets stamped even when
the physical CREATE TABLE silently fails.

---

## Speckit Workflow

```markdown
# 1. Branch
/speckit.git.feature "berlindb-migration"

# 2. Specify
/speckit.specify "Migrate the four custom-dbDelta DB modules in
includes/Database/ (MCPServer, CliAuthLog, OAuthToken, OAuthAudit) to extend
BerlinDB Core 3.0 base classes (\\BerlinDB\\Database\\Kern\\Table, Schema,
Query, Row). Preserve table names ({prefix}acrossai_mcp_servers,
{prefix}acrossai_mcp_cli_auth_logs, {prefix}acrossai_mcp_oauth_tokens,
{prefix}acrossai_mcp_oauth_audit) and db_version_key option names
(acrossai_mcp_manager_db_version, acrossai_mcp_cli_auth_log_db_version,
acrossai_mcp_oauth_tokens_db_version, acrossai_mcp_oauth_audit_db_version)
byte-for-byte. Preserve the public Query API surface (query, add_item,
update_item, delete_item) and the bespoke methods redeem_atomic,
delete_expired_oauth_codes, delete_older_than plus OAuthToken's active_only
custom filter. Add the phantom-version guard override on every Table subclass
to self-heal the observed 'wp_acrossai_mcp_servers doesn't exist' activation
bug. Move MCPServer's insert_default_server() logic into a separate
DefaultServerSeeder::seed() helper called from Activator::activate()
immediately after Table::instance()->maybe_upgrade(). Replace the four
class_exists+Query::maybe_create_table() blocks in Activator::activate() with
direct Table::instance()->maybe_upgrade() calls; delete the static
Query::maybe_create_table() wrapper methods. Fix activation-time autoloader
timing by adding a require_once vendor/autoload_packages.php inside
acrossai_mcp_manager_activate() BEFORE requiring the Activator, so the
BerlinDB Kern base classes and the four Query FQNs are autoloadable during
the activation hook. Do not touch any external caller of the four Query
classes (includes/OAuth/**, includes/REST/CliController.php,
includes/MCP/Controller.php, includes/Database/CliAuthLog/Recorder.php,
admin/Partials/CliAuthLogListTable.php). Do not migrate data. Do not rename
tables or option keys. Do not change Row public-property lists or the
to_array() helper. Memory hygiene per PATTERN-MEMORY-SUPERSESSION-VS-
ANNOTATION: mark any DEC-DBDELTA-* or DEC-CUSTOM-TABLE-LIFECYCLE decisions
as Superseded (Feature 011); annotate patterns that survive with
forward-pointer notes."
```

### Detailed Description for `/speckit.specify`

> **Before writing a single line of code, read and internalize all four of
> these governing documents in full:**
>
> 1. `AGENTS.md` — this plugin's singleton pattern, hook registration rules,
>    Before Commit Checklist.
> 2. The sibling plugin's reference for the target pattern:
>    `/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/plugins/acrossai-abilities-manager/includes/Modules/Abilities/Database/AcrossAI_Abilities_{Table,Schema,Query,Row}.php`
>    (Feature 038-onward). The `maybe_upgrade()` override at
>    `AcrossAI_Abilities_Table.php:96-101` is the canonical phantom-version
>    guard implementation.
> 3. BerlinDB v3 base classes at `vendor/berlindb/core/src/Database/Kern/` —
>    read `Table.php`, `Schema.php`, `Query.php`, `Row.php` to understand
>    which properties are `protected` (must be declared in subclasses) vs
>    inherited defaults.
> 4. Existing feature docs in `docs/planings-tasks/`: `010-composer-dependencies.md`
>    for the composer-add trail, `phase-2-core-boot.md` for the Activator
>    contract, `phase-cli-auth.md` + `phase-6-oauth.md` for the CliAuthLog +
>    OAuthToken + OAuthAudit lifecycles that this feature preserves.
>
> Every decision — column-def translation, index-name preservation, seed-row
> extraction, autoloader require ordering — must be justified against the
> above. If a choice is not explicitly covered, default to the sibling
> plugin's shape. Do not write code that would fail any Definition-of-Done
> gate: PHPStan level 8, PHPCS, security review, all `__()` calls using the
> correct text domain `'acrossai-mcp-manager'`.
>
> **Public API artifacts to preserve verbatim (grep-gate before + after):**
>
> - `\AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query`
> - `\AcrossAI_MCP_Manager\Includes\Database\MCPServer\Row`
> - `\AcrossAI_MCP_Manager\Includes\Database\CliAuthLog\Query`
> - `\AcrossAI_MCP_Manager\Includes\Database\CliAuthLog\Row`
> - `\AcrossAI_MCP_Manager\Includes\Database\OAuthToken\Query`
> - `\AcrossAI_MCP_Manager\Includes\Database\OAuthToken\Row`
> - `\AcrossAI_MCP_Manager\Includes\Database\OAuthAudit\Query`
> - `\AcrossAI_MCP_Manager\Includes\Database\OAuthAudit\Row`
>
> Pre-flight grep (records the callers whose behavior must be unchanged after
> the migration):
> ```
> grep -rEn '(new [A-Za-z_]*Query|use .*(MCPServer|CliAuthLog|OAuthToken|OAuthAudit)\\Query)' \
>     --include='*.php' \
>     includes/ admin/ public/ acrossai-mcp-manager.php
> ```
> Every hit that surfaces here MUST still resolve — same class name, same
> method signatures, same return shape — after every TASK. Any grep result
> that would break requires a caller-side follow-up out of Feature 011 scope.
>
> Preserved table + option map (data-preservation contract):
>
> | Module | Table (with `$wpdb->prefix`) | `db_version_key` option | `$version` (current DB_VERSION) |
> | --- | --- | --- | --- |
> | MCPServer | `acrossai_mcp_servers` | `acrossai_mcp_manager_db_version` | `0.0.1` |
> | CliAuthLog | `acrossai_mcp_cli_auth_logs` | `acrossai_mcp_cli_auth_log_db_version` | `0.0.2` |
> | OAuthToken | `acrossai_mcp_oauth_tokens` | `acrossai_mcp_oauth_tokens_db_version` | `0.0.1` |
> | OAuthAudit | `acrossai_mcp_oauth_audit` | `acrossai_mcp_oauth_audit_db_version` | `0.0.1` |
>
> ---
>
> **TASK-1 — Fix activation-time autoloader timing**
>
> Files: `acrossai-mcp-manager.php`
>
> Read the current activation-hook sequence at `acrossai-mcp-manager.php`
> lines 53-93 before editing. The existing priority-1 pre-guard on
> `activate_<plugin>` only checks that `vendor/autoload_packages.php` EXISTS
> — it doesn't `require` it. Meanwhile the priority-10 callback
> (`acrossai_mcp_manager_activate()`) requires `includes/Activator.php`,
> whose class-level `use` imports reference the four Query FQNs. WordPress
> autoloader trigger occurs at `class_exists()` — but the vendor autoloader
> is only registered by `Main::__construct()` at `plugins_loaded` P0, which
> hasn't fired during activation.
>
> Inside `acrossai_mcp_manager_activate()` (currently lines 53-56), add
> ONE line BEFORE `require_once .../includes/Activator.php`:
>
> ```php
> require_once __DIR__ . '/vendor/autoload_packages.php';
> ```
>
> Update the priority-1 pre-guard docblock (lines 71-79) to note that this
> callback stays as a fail-early check but the required-guarded require now
> happens inside the P10 activate callback. Keep the wp_die branch on
> `! file_exists( ... )` — it prevents fatal on a missing vendor install.
>
> Do NOT change the priority-1 pre-guard body itself. Do NOT move the
> require to the plugin file's global scope — activation is the only site
> that needs it before `plugins_loaded`.
>
> ---
>
> **TASK-2 — MCPServer BerlinDB rewrite (fixes missing-table bug)**
>
> Files:
> - `includes/Database/MCPServer/Table.php` (full rewrite)
> - `includes/Database/MCPServer/Schema.php` (full rewrite)
> - `includes/Database/MCPServer/Query.php` (full rewrite — API preserved)
> - `includes/Database/MCPServer/Row.php` (full rewrite — public properties preserved)
> - `includes/Database/MCPServer/DefaultServerSeeder.php` (NEW)
> - `includes/Activator.php` (delta: replace MCPServer block only)
>
> Read the sibling plugin's four Database class files (paths in the
> Governing Docs list) plus the current `MCPServer/Table.php` CREATE TABLE
> DDL BEFORE editing.
>
> Table subclass — declare protected properties matching sibling pattern:
> ```php
> protected $name = 'acrossai_mcp_servers';
> protected $version = '0.0.1';
> protected $db_version_key = 'acrossai_mcp_manager_db_version';
> protected $schema = Schema::class;
> protected $global = false;
> ```
> Add singleton `instance()` returning `self`. Override `maybe_upgrade()`
> verbatim from `AcrossAI_Abilities_Table.php:96-101` (adjust class name):
> ```php
> public function maybe_upgrade(): void {
>     if ( ! $this->exists() ) {
>         delete_option( $this->db_version_key );
>     }
>     parent::maybe_upgrade();
> }
> ```
> Delete the old `TABLE_NAME` / `DB_VERSION` / `DB_VERSION_OPTION` /
> `CACHE_GROUP` / `DEFAULT_SERVER_SLUG` constants — BerlinDB owns lifecycle
> now. If `DEFAULT_SERVER_SLUG` is referenced from OTHER files (grep
> BEFORE deletion), move the constant to `DefaultServerSeeder::SLUG` and
> update the references.
>
> Schema subclass — translate the current `CREATE TABLE` columns (Table.php
> lines 62-78) into BerlinDB `$columns` array:
> ```php
> public $columns = array(
>     array( 'name' => 'id', 'type' => 'bigint', 'length' => '20',
>            'unsigned' => true, 'extra' => 'auto_increment', 'sortable' => true ),
>     array( 'name' => 'server_name', 'type' => 'varchar', 'length' => '255' ),
>     array( 'name' => 'server_slug', 'type' => 'varchar', 'length' => '255',
>            'default' => '', 'sortable' => true, 'searchable' => true ),
>     array( 'name' => 'description', 'type' => 'varchar', 'length' => '500', 'default' => '' ),
>     array( 'name' => 'is_enabled', 'type' => 'tinyint', 'length' => '1', 'default' => 0 ),
>     array( 'name' => 'registered_from', 'type' => 'varchar', 'length' => '50', 'default' => 'plugin' ),
>     array( 'name' => 'server_route_namespace', 'type' => 'varchar', 'length' => '100', 'default' => 'mcp' ),
>     array( 'name' => 'server_route', 'type' => 'varchar', 'length' => '255', 'default' => '' ),
>     array( 'name' => 'server_version', 'type' => 'varchar', 'length' => '50', 'default' => 'v1.0.0' ),
>     array( 'name' => 'claude_connector_client_id', 'type' => 'varchar', 'length' => '255', 'default' => '' ),
>     array( 'name' => 'claude_connector_client_secret', 'type' => 'varchar', 'length' => '255', 'default' => '' ),
>     array( 'name' => 'claude_connector_redirect_uri', 'type' => 'varchar', 'length' => '500', 'default' => '' ),
>     array( 'name' => 'created_at', 'type' => 'datetime', 'default' => 'CURRENT_TIMESTAMP',
>            'sortable' => true, 'date_query' => true ),
> );
> public $indexes = array(
>     array( 'name' => 'primary', 'type' => 'primary', 'columns' => array( 'id' ) ),
>     array( 'name' => 'server_slug', 'type' => 'key', 'columns' => array( 'server_slug' ) ),
> );
> ```
> Types + lengths + defaults MUST match the existing DDL byte-for-byte.
>
> Query subclass — declare BerlinDB properties matching sibling pattern:
> ```php
> protected $table_name = 'acrossai_mcp_servers';
> protected $table_alias = 'mcps';
> protected $table_schema = Schema::class;
> protected $item_name = 'mcp_server';
> protected $item_name_plural = 'mcp_servers';
> protected $item_shape = Row::class;
> ```
> Add singleton `instance()`. DELETE the hand-written `query()`,
> `add_item()`, `update_item()`, `delete_item()`, `maybe_create_table()`
> method bodies — BerlinDB provides these with matching signatures. The
> static `Query::maybe_create_table()` wrapper is deleted; Activator now
> calls `Table::instance()->maybe_upgrade()` directly.
>
> Row subclass — extend `\BerlinDB\Database\Kern\Row`. Keep the current
> public-property list (`id`, `server_name`, `server_slug`, `description`,
> `is_enabled`, `registered_from`, `server_route_namespace`, `server_route`,
> `server_version`, `claude_connector_client_id`,
> `claude_connector_client_secret`, `claude_connector_redirect_uri`,
> `created_at`) and the `to_array()` helper — external code depends on
> both.
>
> DefaultServerSeeder — new file. Static `seed()` method reproduces the
> current `MCPServer/Table::insert_default_server()` logic verbatim: SELECT
> COUNT via prepared `%i`, return early if non-zero, otherwise `wpdb->insert()`
> the default row (`server_name` = 'Default MCP Server', `server_slug` =
> DEFAULT_SERVER_SLUG constant, description, is_enabled = 0,
> registered_from = 'plugin', server_route_namespace = 'mcp', server_route =
> DEFAULT_SERVER_SLUG, server_version = 'v1.0.0', empty claude_connector
> fields). Also `wp_cache_delete( 'all_servers', 'acrossai_mcp' )` at the
> end.
>
> Activator delta — remove ONLY the MCPServer block (currently lines 31-33
> of the class_exists chain) and replace with:
> ```php
> use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Table as MCPServerTable;
> use AcrossAI_MCP_Manager\Includes\Database\MCPServer\DefaultServerSeeder;
> ...
> MCPServerTable::instance()->maybe_upgrade();
> DefaultServerSeeder::seed();
> ```
> Leave the other three modules' `class_exists`+`maybe_create_table` blocks
> UNTOUCHED — they land in TASK-3..5.
>
> ---
>
> **TASK-3 — CliAuthLog BerlinDB rewrite**
>
> Files:
> - `includes/Database/CliAuthLog/Table.php` (full rewrite)
> - `includes/Database/CliAuthLog/Schema.php` (full rewrite)
> - `includes/Database/CliAuthLog/Query.php` (full rewrite — API + bespoke methods preserved)
> - `includes/Database/CliAuthLog/Row.php` (full rewrite)
> - `includes/Activator.php` (delta: replace CliAuthLog block only)
>
> Same pattern as TASK-2 with the CliAuthLog table's DDL and API. Preserve
> `$version = '0.0.2'` and `$db_version_key = 'acrossai_mcp_cli_auth_log_db_version'`.
>
> Column translation (from the existing DDL): the 15 columns
> (`id`, `server_id`, `server_slug`, `user_id`, `status`, `failure_code`,
> `auth_code_hash`, `app_password_uuid`, `redirect_uri`, `code_challenge`,
> `code_challenge_method`, `scope`, `approved_at`, `completed_at`,
> `created_at`) with `auth_code_hash` as `char length 64`, `code_challenge`
> as `char length 43`, `approved_at`/`completed_at` as nullable datetimes.
>
> Indexes (all four MUST be preserved verbatim):
> ```php
> array( 'name' => 'primary', 'type' => 'primary', 'columns' => array( 'id' ) ),
> array( 'name' => 'auth_code_hash', 'type' => 'unique', 'columns' => array( 'auth_code_hash' ) ),
> array( 'name' => 'server_created', 'type' => 'key', 'columns' => array( 'server_id', 'created_at' ) ),
> array( 'name' => 'server_status_created', 'type' => 'key',
>        'columns' => array( 'server_id', 'status', 'created_at' ) ),
> ```
>
> Query subclass — carry over the bespoke methods verbatim (they use raw
> `$wpdb->query( $wpdb->prepare( ... ) )` so are base-class-agnostic):
> - `redeem_atomic( int $id, string $now ): bool` (SEC-001 atomic redeem —
>   MUST preserve the `WHERE id = %d AND completed_at IS NULL` guard, MUST
>   return `1 === (int) $wpdb->rows_affected`).
> - `delete_expired_oauth_codes( string $cutoff ): int` (bulk delete for
>   FR-019c retention cron).
>
> Row public properties + `to_array()` preserved as-is.
>
> Activator delta: replace only the CliAuthLog block.
>
> ---
>
> **TASK-4 — OAuthToken BerlinDB rewrite**
>
> Files:
> - `includes/Database/OAuthToken/Table.php` (full rewrite)
> - `includes/Database/OAuthToken/Schema.php` (full rewrite)
> - `includes/Database/OAuthToken/Query.php` (full rewrite — API + active_only filter preserved)
> - `includes/Database/OAuthToken/Row.php` (full rewrite)
> - `includes/Activator.php` (delta: replace OAuthToken block only)
>
> Preserve `$version = '0.0.1'` and `$db_version_key = 'acrossai_mcp_oauth_tokens_db_version'`.
>
> Column translation (9 columns): `id`, `access_token_hash` (char 64),
> `server_id`, `user_id`, `issued_from_code_id`, `scope` (varchar 64,
> default 'mcp'), `created_at`, `expires_at`, `revoked_at` (nullable).
>
> Indexes:
> ```php
> array( 'name' => 'primary', 'type' => 'primary', 'columns' => array( 'id' ) ),
> array( 'name' => 'access_token_hash', 'type' => 'unique', 'columns' => array( 'access_token_hash' ) ),
> array( 'name' => 'server_expires', 'type' => 'key', 'columns' => array( 'server_id', 'expires_at' ) ),
> array( 'name' => 'user_created', 'type' => 'key', 'columns' => array( 'user_id', 'created_at' ) ),
> array( 'name' => 'issued_from_code', 'type' => 'key', 'columns' => array( 'issued_from_code_id' ) ),
> ```
>
> Query subclass — the OAuthToken Query supports a custom `active_only`
> boolean filter that the current `query()` method interprets as
> `revoked_at IS NULL AND expires_at > NOW()`. BerlinDB's base `query()`
> does not know this filter. Add a subclass override:
> ```php
> public function query( $query = array(), $filter = true ) {
>     // Consume our custom filter before delegating to parent.
>     $active_only = ! empty( $query['active_only'] );
>     unset( $query['active_only'] );
>     $items = parent::query( $query, $filter );
>     if ( $active_only ) {
>         $now = current_time( 'mysql', 1 );
>         $items = array_values( array_filter( $items, static function ( $row ) use ( $now ) {
>             return null === $row->revoked_at && $row->expires_at > $now;
>         } ) );
>     }
>     return $items;
> }
> ```
> (Alternative: implement `active_only` as a raw where-clause via
> BerlinDB's `Where` operator — pick whichever the sibling plugin's Query
> pattern already prefers.)
>
> Row public properties + `to_array()` preserved as-is.
>
> Activator delta: replace only the OAuthToken block.
>
> ---
>
> **TASK-5 — OAuthAudit BerlinDB rewrite**
>
> Files:
> - `includes/Database/OAuthAudit/Table.php` (full rewrite)
> - `includes/Database/OAuthAudit/Schema.php` (full rewrite)
> - `includes/Database/OAuthAudit/Query.php` (full rewrite — API + delete_older_than preserved)
> - `includes/Database/OAuthAudit/Row.php` (full rewrite)
> - `includes/Activator.php` (delta: replace OAuthAudit block only)
>
> Preserve `$version = '0.0.1'` and `$db_version_key = 'acrossai_mcp_oauth_audit_db_version'`.
>
> Column translation (9 columns): `id`, `event_type` (varchar 64),
> `server_id`, `user_id`, `client_id` (varchar 255), `token_hash_prefix`
> (char 8), `endpoint` (varchar 255), `details_json` (text, nullable),
> `created_at`.
>
> Indexes:
> ```php
> array( 'name' => 'primary', 'type' => 'primary', 'columns' => array( 'id' ) ),
> array( 'name' => 'event_created', 'type' => 'key', 'columns' => array( 'event_type', 'created_at' ) ),
> array( 'name' => 'server_created', 'type' => 'key', 'columns' => array( 'server_id', 'created_at' ) ),
> array( 'name' => 'user_created', 'type' => 'key', 'columns' => array( 'user_id', 'created_at' ) ),
> ```
>
> Query subclass — carry over the bespoke `delete_older_than( string $datetime ): int`
> method verbatim (raw `$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', ... ) )`
> per FR-019b retention cron).
>
> Row public properties + `to_array()` preserved as-is.
>
> Activator delta: replace only the OAuthAudit block.
>
> ---
>
> **TASK-6 — Final Activator cleanup**
>
> Files: `includes/Activator.php`
>
> After TASK-2..5 land the four Table calls, the Activator will still
> contain the OLD `use ... Query as XxxQuery;` imports and the residual
> `class_exists( XxxQuery::class ) { XxxQuery::maybe_create_table(); }`
> pattern is fully gone. This task verifies + cleans:
>
> - Remove any stale `use` import that references a Query class not used
>   elsewhere in `Activator::activate()`.
> - Confirm the four `use ... Table as XxxTable;` imports exist (added
>   incrementally in TASK-2..5).
> - Confirm the `activate()` method contains exactly four
>   `XxxTable::instance()->maybe_upgrade();` calls plus the one
>   `DefaultServerSeeder::seed();` call, plus the unchanged rewrite-rule
>   + cron scheduling blocks.
>
> Do NOT add defensive `class_exists( XxxTable::class )` guards — after
> TASK-1's autoloader fix the FQNs are guaranteed to resolve; a `class_exists`
> guard here would mask a real regression.
>
> ---
>
> **TASK-7 — Callers verification sweep**
>
> Files: (none — grep-only)
>
> Read the pre-flight grep captured in the Public API section BEFORE running
> this task. Re-run the SAME grep and diff the results.
>
> Expected: 100% of the pre-flight hits still match. If any call site now
> breaks (missing method, changed return shape), the Query subclass in
> TASK-2..5 did not preserve API — fix the subclass, do NOT change the caller.
>
> Additional callers-integrity check:
>
> ```
> grep -rEn '\\bmaybe_create_table\\b' \
>     --include='*.php' \
>     includes/ admin/ public/ acrossai-mcp-manager.php
> ```
> Expected result: zero matches. Any hit is a stale reference to the
> deleted static Query wrapper.
>
> ---
>
> **TASK-8 — Memory hygiene + changelog**
>
> Files: `README.txt`, `docs/memory/DECISIONS.md`, `docs/memory/WORKLOG.md`,
> `docs/memory/INDEX.md`, `docs/planings-tasks/README.md`
>
> Read `PATTERN-MEMORY-SUPERSESSION-VS-ANNOTATION` (from sibling plugin's
> `docs/memory/ARCHITECTURE.md` if this plugin's copy is absent) before
> editing.
>
> `README.txt` — add an Unreleased changelog bullet:
> ```
> * Migrated the four internal DB modules (MCP Servers, CLI Auth Log,
>   OAuth Tokens, OAuth Audit) to BerlinDB Core 3.0 for a self-healing
>   install lifecycle. Fixes an activation edge-case where the MCP Servers
>   table failed to create when the version option had been stamped without
>   a successful CREATE TABLE. No data migration required; existing tables
>   are recognized in-place.
> ```
>
> `docs/memory/DECISIONS.md` — mark any of the following decisions as
> **Superseded (Feature 011)** if present in current memory (keep entry
> body intact per PATTERN-MEMORY-SUPERSESSION-VS-ANNOTATION):
> - Any DEC-DBDELTA-* entry.
> - Any DEC-CUSTOM-TABLE-LIFECYCLE entry.
> - Any DEC-DB-VERSION-OPTION-GUARD entry.
> - The pre-existing `sanitize_key()` / `%i` placeholder decisions REMAIN
>   valid — do NOT supersede those.
>
> `docs/memory/WORKLOG.md` — add a Feature 011 milestone entry (Why durable
> / Future mistake prevented / Evidence / Where to look). Highlight the
> durable lesson: **a version-option stamp WITHOUT a physical-table
> existence check is a common self-healing gap — the phantom-version guard
> at `Table::maybe_upgrade()` is the canonical fix and applies to every
> BerlinDB-backed table this plugin ever adds**.
>
> `docs/memory/INDEX.md` — update Superseded rows for any retired decisions
> plus append a WORKLOG row for Feature 011.
>
> `docs/planings-tasks/README.md` — append a row for `011-berlindb-migration.md`
> to the docs index (or append it to whatever list-of-features table exists
> in that README).
>
> ---
>
> **CONSTRAINTS**
>
> - **Do not rename tables.** The four table names above are the
>   backwards-compatibility contract with any prior install's data.
> - **Do not rename `db_version_key` option names.** Same rationale — any
>   rename triggers a phantom fresh install on healthy production sites.
> - **Do not change public Query API surface.** `query( $args )`,
>   `add_item( $data )`, `update_item( $id, $data )`, `delete_item( $id )`,
>   `redeem_atomic( $id, $now )`, `delete_expired_oauth_codes( $cutoff )`,
>   `delete_older_than( $datetime )` all keep their current signatures.
> - **Do not delete `Row::to_array()`.** External consumers (list tables,
>   admin views, REST controllers) depend on it.
> - **Do not skip the phantom-version guard on any of the four Table
>   subclasses.** Even OAuthAudit / OAuthToken / CliAuthLog — which today
>   appear healthy — could hit the same edge case; the guard is cheap
>   defense.
> - **Do not add a data migration step.** BerlinDB's `maybe_upgrade()` diff
>   engine handles the "table already exists" case natively. Any manual
>   `INSERT ... SELECT` in the Activator is out of scope and forbidden.
> - **Do not touch any file under `vendor/`.** No composer dependency is
>   removed or bumped by this feature.
> - **Do not touch external callers.** The eight FQNs listed in "Public
>   API artifacts" are the boundary; anything under
>   `includes/OAuth/`, `includes/REST/`, `includes/MCP/`,
>   `includes/Database/CliAuthLog/Recorder.php`, or `admin/Partials/`
>   receives ZERO edits in Feature 011.
> - **Do not delete Feature 010's composer.json changes.** `berlindb/core:
>   ^3.0.0` MUST stay declared.
> - **Do not delete Feature 009's MCP Controller.** The guard-and-graceful-
>   degrade pattern at `includes/MCP/Controller.php` around
>   `class_exists('\\WP\\MCP\\Plugin')` stays exactly as-is.
> - **Every task must leave PHPStan level 8 + PHPCS individually green
>   before moving to the next.** Constitution §VII per-task gating applies.
> - **BerlinDB Schema `$columns` MUST match existing SQL DDL byte-for-byte**
>   (types, lengths, defaults, nullability). Any mismatch fires ALTER on
>   production installs — the reviewer MUST verify with
>   `SHOW CREATE TABLE {name}` before merge on any install with existing data.
> - **`$indexes` MUST replicate every existing PRIMARY KEY / UNIQUE KEY /
>   KEY from the current CREATE TABLE statements — verbatim index names.**
>   BerlinDB uses the `name` field for diff-matching; renaming an index
>   equals drop + create.
> - **Grep after every task** for stale references to the removed static
>   `Query::maybe_create_table()` helpers. The Final full-repo audit at the
>   bottom MUST return zero matches.

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

# 6. Review + memory + commit
/speckit.analyze
/speckit.architecture-guard.architecture-review
/speckit.security-review.staged
/speckit.memory-md.capture-from-diff
/speckit.git.commit
```

---

## Manual Verification Checklist

### TASK-1 — Autoloader-timing fix
- [ ] `acrossai-mcp-manager.php::acrossai_mcp_manager_activate()` requires
      `vendor/autoload_packages.php` BEFORE requiring the Activator.
- [ ] The priority-1 pre-guard on `activate_<plugin>` still `wp_die`s on a
      missing `vendor/autoload_packages.php`.
- [ ] Fresh activation on a WP install with intact vendor dir: no fatal,
      Activator runs to completion.
- [ ] `error_log` and `WP_DEBUG_LOG` are silent on activation.

### TASK-2 — MCPServer BerlinDB rewrite
- [ ] `includes/Database/MCPServer/{Table,Schema,Query,Row}.php` all
      extend the corresponding `\BerlinDB\Database\Kern\*` class.
- [ ] `includes/Database/MCPServer/DefaultServerSeeder.php` exists and
      defines `public static function seed(): void`.
- [ ] Fresh activation: `SHOW TABLES LIKE 'wp_acrossai_mcp_servers'`
      returns one row.
- [ ] `SELECT * FROM wp_acrossai_mcp_servers` shows the default seeded row
      with `server_slug = 'mcp-adapter-default-server'`, `is_enabled = 0`.
- [ ] On the previously-broken install (option stamped, table absent):
      after this task's activator run, the table exists and contains the
      seeded row.
- [ ] Existing install (option stamped, table present, healthy):
      reactivation runs zero `ALTER TABLE` (verify `SHOW WARNINGS` empty
      + `debug.log` clean).

### TASK-3 — CliAuthLog BerlinDB rewrite
- [ ] All four class files extend `\BerlinDB\Database\Kern\*`.
- [ ] `Query::redeem_atomic( int $id, string $now ): bool` preserves the
      `WHERE id = %d AND completed_at IS NULL` guard and returns
      `1 === (int) $wpdb->rows_affected`.
- [ ] `Query::delete_expired_oauth_codes( string $cutoff ): int` returns
      the delete count.
- [ ] `SHOW TABLES LIKE 'wp_acrossai_mcp_cli_auth_logs'` returns one row.
- [ ] `SHOW CREATE TABLE wp_acrossai_mcp_cli_auth_logs` output preserves
      all four expected indexes (PRIMARY, auth_code_hash UNIQUE,
      server_created KEY, server_status_created KEY).
- [ ] SEC-001 concurrency test: two simultaneous `redeem_atomic()` calls
      on the same code row — exactly ONE returns `true`.

### TASK-4 — OAuthToken BerlinDB rewrite
- [ ] All four class files extend `\BerlinDB\Database\Kern\*`.
- [ ] `Query::query( [ 'active_only' => true, 'user_id' => X ] )` returns
      only rows where `revoked_at IS NULL AND expires_at > NOW()`.
- [ ] `SHOW TABLES LIKE 'wp_acrossai_mcp_oauth_tokens'` returns one row.
- [ ] `SHOW CREATE TABLE` preserves all five expected indexes.

### TASK-5 — OAuthAudit BerlinDB rewrite
- [ ] All four class files extend `\BerlinDB\Database\Kern\*`.
- [ ] `Query::delete_older_than( string $datetime ): int` returns the
      delete count and only deletes rows with `created_at < $datetime`.
- [ ] `SHOW TABLES LIKE 'wp_acrossai_mcp_oauth_audit'` returns one row.
- [ ] `SHOW CREATE TABLE` preserves all four expected indexes.

### TASK-6 — Final Activator cleanup
- [ ] `includes/Activator.php::activate()` contains exactly four
      `Table::instance()->maybe_upgrade()` calls (one per module) and one
      `DefaultServerSeeder::seed()` call.
- [ ] Zero `class_exists( ...Query::class )` guards remain in the method.
- [ ] Zero `Query::maybe_create_table()` calls remain in the method.
- [ ] The rewrite-rule flush + `wp_schedule_event( 'acrossai_mcp_oauth_cleanup' )`
      cron scheduling blocks are unchanged.

### TASK-7 — Callers verification sweep
- [ ] Pre-flight grep result (captured at the start of Feature 011) 100%
      matches post-migration grep result — same class FQNs, same method
      names, same call-site line ranges (allowing for imports moving).
- [ ] `grep -rEn '\\bmaybe_create_table\\b' includes/ admin/ public/`
      returns zero matches.
- [ ] `grep -rEn 'DB_VERSION_OPTION|TABLE_NAME' includes/ admin/ public/`
      returns zero matches under `includes/Database/` (constants deleted).
- [ ] End-to-end smoke: create + edit + toggle-enable an MCP server via
      the admin UI; complete a CLI OAuth handshake against a running MCP
      server; issue a bearer token; verify a row lands in `oauth_audit`.

### TASK-8 — Release notes + memory hygiene
- [ ] `README.txt` Unreleased changelog contains the BerlinDB migration
      bullet.
- [ ] `docs/memory/DECISIONS.md`: any DEC-DBDELTA-* or
      DEC-CUSTOM-TABLE-LIFECYCLE or DEC-DB-VERSION-OPTION-GUARD entries
      that existed are now marked Superseded (Feature 011) with the
      original body intact.
- [ ] `docs/memory/WORKLOG.md`: Feature 011 milestone entry added.
- [ ] `docs/memory/INDEX.md`: Superseded rows updated + WORKLOG row
      appended.
- [ ] `docs/planings-tasks/README.md` lists `011-berlindb-migration.md`.

### Final full-repo audit (blocker before merge)

```bash
grep -rEn 'dbDelta|\bmaybe_create_table\b|DB_VERSION_OPTION|extends\s+Table\s*\{|extends\s+Schema\s*\{' \
    --include='*.php' \
    includes/ admin/ public/ acrossai-mcp-manager.php uninstall.php
```

- [ ] Grep returns **zero matches** under `includes/Database/**` for any of
      the retired symbols (`dbDelta`, `maybe_create_table`, `DB_VERSION_OPTION`,
      and any `extends Table` / `extends Schema` NOT rooted in
      `\BerlinDB\Database\Kern\`). Hits inside `uninstall.php` for
      explicit `DROP TABLE` names are permitted.

### Quality gates (all must be green before commit)
- [ ] PHPStan level 8 — zero errors.
- [ ] PHPCS — zero errors.
- [ ] `composer test` — PHPUnit all remaining tests pass.
- [ ] `composer dump-autoload` — succeeds with zero warnings.
- [ ] `SHOW TABLES LIKE 'wp_acrossai_mcp_%'` on a clean install returns
      exactly four rows.
- [ ] `SELECT option_name, option_value FROM wp_options WHERE option_name
      LIKE 'acrossai_mcp%_db_version'` returns exactly four rows, one per
      module, with values matching each Table's `$version`.


---

## Pre-flight Attestation (SEC-011-004 / T001)

**Captured**: 2026-07-02 via `AskUserQuestion` during `/speckit-analyze` compat-drop scoping.

**Attestation**: No site outside `~/local-sites/` runs this plugin against real MCP server data or real OAuth-issued tokens. The plugin is dev/local only; no live install has a populated pre-migration schema that Feature 011 could orphan.

**Basis for**: FR-020..027 compat-drop authorization (table renames, `db_version_key` renames, column restructures, API breakage, caller-sweep edits under `admin/Partials/`).

**Attesting user**: raftaar1191@gmail.com

**Validity window**: 2026-07-02 → Feature 011 merge. Any new install between attestation and merge invalidates the compat-drop premise and requires re-scoping.

---

## Emergent Fixes (post-workflow — 2026-07-02)

### T044 — Request-time Table boot in Main.php

**Symptom** (live error log 2026-07-02 16:12:56 UTC):
```
WordPress database error Table 'local.mcps' doesn't exist for query
  SELECT `mcps`.`id` FROM  mcps ORDER BY `mcps`.`id` ASC LIMIT 100
made by ... MCPServerListTable->prepare_items,
         BerlinDB\Database\Kern\Query->query, ...
```
Second identical error from `MCP\Controller::has_any_enabled_server` on `rest_api_init`.

**Root cause**: BerlinDB v3 requires the Table subclass to be **instantiated** at request time so its `sunrise()` boot registers `$wpdb->prefix . $name` with the global DB interface. Feature 011 only called `Table::instance()` in `Activator::activate()` (activation-time), never at request-time. Query fell back to using `$table_alias` as the FROM clause.

**Fix**: Added `Main::bootstrap_database_tables()` private method to `includes/Main.php`. Called from `Main::load_hooks()` inside the `apply_filters( 'acrossai_mcp_manager_load', true )` gate, before `define_admin_hooks()` and `define_public_hooks()`. Instantiates all four Table subclasses per request. Matches sibling plugin `acrossai-abilities-manager` `Main::define_admin_hooks:349` boot pattern.

**Spec/tasks fold-back**: FR-028 added to spec.md; T044 marked complete in tasks.md; DEC-BERLINDB-TABLE-REQUEST-BOOT to be captured in DECISIONS.md via T045.

### T045 — Two new DECISIONS.md entries

**DEC-BERLINDB-TABLE-REQUEST-BOOT (Active — Feature 011)**: BerlinDB Table subclasses MUST be instantiated at request time via `Main::load_hooks()`. Applies to every future BerlinDB-backed table this plugin adds. Reference: FR-028; live evidence above; sibling plugin `Main::define_admin_hooks:349`.

**DEC-BERLINDB-SUBCLASS-NO-USE-COLLISION (Active — Feature 011)**: When a plugin's subclass name matches its BerlinDB Kern parent's class name (`class Table extends \BerlinDB\Database\Kern\Table`), do NOT add `use BerlinDB\Database\Kern\Table;` — the `use` claims the same local symbol name and produces "Cannot redeclare class ... previously declared as local import" fatals. Either drop the `use` (extend via leading-`\` FQN — what Feature 011 does in the `includes/Database/<Module>/` layout) OR alias the import. Origin: Feature 011 workflow template bug caught by `php -l` post-implementation across 14 files.

Both entries need companion rows in `docs/memory/INDEX.md` under Active Decisions per FR-025.

---

## T043 — Evidence Collation Template (fill in after T023 + T025 + PHPUnit)

This section is the merge-gate evidence pack. Every check below must be filled in before Feature 011 can merge to `main`. Empty checkboxes = blocking.

### 1. T023 — Fresh-install activation smoke (SC-001)

**Preconditions**:
- Plugin deactivated.
- `DROP TABLE IF EXISTS wp_acrossai_mcp_servers, wp_acrossai_mcp_cli_auth_logs, wp_acrossai_mcp_oauth_tokens, wp_acrossai_mcp_oauth_audit;`
- `DELETE FROM wp_options WHERE option_name IN ('acrossai_mcp_servers_db_version', 'acrossai_mcp_cli_auth_logs_db_version', 'acrossai_mcp_oauth_tokens_db_version', 'acrossai_mcp_oauth_audit_db_version');`

**Steps**:
1. Activate the plugin via WP admin plugins screen.
2. Verify no PHP fatal in `wp-content/debug.log` or the WP admin bar.
3. Run the WP-CLI verifications below.

**Evidence (paste output here)**:

```
$ wp option get acrossai_mcp_servers_db_version
<paste output — expected: 1.0.0>

$ wp option get acrossai_mcp_cli_auth_logs_db_version
<paste — expected: 1.0.0>

$ wp option get acrossai_mcp_oauth_tokens_db_version
<paste — expected: 1.0.0>

$ wp option get acrossai_mcp_oauth_audit_db_version
<paste — expected: 1.0.0>

$ wp db query "SHOW TABLES LIKE 'wp_acrossai_mcp_%'"
<paste — expected: 4 rows — wp_acrossai_mcp_servers, wp_acrossai_mcp_cli_auth_logs, wp_acrossai_mcp_oauth_tokens, wp_acrossai_mcp_oauth_audit>

$ wp db query "SELECT COUNT(*) FROM wp_acrossai_mcp_servers"
<paste — expected: 1 (the default seeded server row)>

$ wp db query "SELECT server_slug FROM wp_acrossai_mcp_servers"
<paste — expected: mcp-adapter-default-server>
```

**Admin screen check**:
- [ ] Navigate to `/wp-admin/admin.php?page=acrossai_mcp_manager` — MCP Manager server list renders without PHP fatal or `Table ... doesn't exist` error.
- [ ] Default server row appears in the list with slug `mcp-adapter-default-server`.

**Timestamp**: `<YYYY-MM-DD HH:MM UTC>`
**Verifier**: `<user email>`
**Result**: [ ] PASS  [ ] FAIL — attach failure details if FAIL.

### 2. T025 — Phantom-version guard manual test (SC-004)

**Preconditions**: Plugin ACTIVE, all four tables present with the four `db_version_key` options stamped (i.e., T023 completed successfully).

**Steps**:
1. Pick one target table (e.g. `wp_acrossai_mcp_oauth_tokens`).
2. Note the current `acrossai_mcp_oauth_tokens_db_version` value: `wp option get acrossai_mcp_oauth_tokens_db_version` → `1.0.0`.
3. Drop ONE physical table WITHOUT deleting the option: `wp db query "DROP TABLE wp_acrossai_mcp_oauth_tokens"`.
4. Confirm the table is gone AND the option is still stamped.
5. Deactivate + reactivate the plugin (via WP admin, not WP-CLI, so the priority-1 pre-guard also runs).
6. Verify the table is back.
7. Verify silent operation: `tail -50 wp-content/debug.log` between the drop and the reactivation — NO AcrossAI-related log line should appear (Clarification Q1 silent-guard invariant).

**Evidence (paste output here)**:

```
$ wp option get acrossai_mcp_oauth_tokens_db_version
<baseline — expected: 1.0.0>

$ wp db query "DROP TABLE wp_acrossai_mcp_oauth_tokens"
<paste — expected: no error>

$ wp db query "SHOW TABLES LIKE 'wp_acrossai_mcp_oauth_tokens'"
<paste — expected: empty>

$ wp option get acrossai_mcp_oauth_tokens_db_version
<paste — expected: still 1.0.0 (option outlives dropped table)>

<... deactivate + reactivate via wp-admin ...>

$ wp db query "SHOW TABLES LIKE 'wp_acrossai_mcp_oauth_tokens'"
<paste — expected: 1 row>

$ wp option get acrossai_mcp_oauth_tokens_db_version
<paste — expected: 1.0.0>

$ tail -50 wp-content/debug.log | grep -i acrossai
<paste — expected: empty (silent guard per Clarification Q1)>
```

**Timestamp**: `<YYYY-MM-DD HH:MM UTC>`
**Verifier**: `<user email>`
**Result**: [ ] PASS  [ ] FAIL

### 3. PHPUnit execution (SC-005, SC-002 test suite gate)

**Preconditions**: WP test DB up (`tests/bootstrap.php` + `tests/bootstrap-wp.php` chain works).

**Command**:

```
cd /Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/plugins/acrossai-mcp-manager
vendor/bin/phpunit tests/phpunit/Database/
```

**Expected**: 4 test files, N test methods total, zero failures.

**Evidence (paste output here)**:

```
$ vendor/bin/phpunit tests/phpunit/Database/
<paste full output — expected format:
PHPUnit ...

....                                                                N / N (100%)

Time: ..., Memory: ...

OK (N tests, N assertions)>
```

Per-test-file expectations:
- **AtomicCasTest**: 3 assertions minimum — (A) `redeem_atomic` first call returns true + DB `completed_at` non-NULL; (B) second call returns falsy; (C) `$wpdb->last_query` matches `UPDATE .* SET completed_at .* WHERE id = \d+ AND completed_at IS NULL` (BUGS.md B10 predicate assertion — CRITICAL).
- **ActiveOnlyFilterTest**: seeds 3 rows (active + expired + revoked), asserts `active_only` returns exactly 1 (the active row) and empty result is `array()` not `null`.
- **ColumnWidthInvariantTest**: parametrized over 3 crypto columns; asserts `auth_code_hash` char(64), `access_token_hash` char(64), `code_challenge` char(43).
- **PhantomVersionGuardTest**: parametrized over 4 Table subclasses; drops table with option stamped, invokes `maybe_upgrade()`, asserts recreated.

**Result**: [ ] PASS  [ ] FAIL — attach failing assertions if FAIL.

### 4. T033 — FR-010 column-width verification (already run in-workflow; re-verify here for the record)

```
$ grep -A2 "auth_code_hash" includes/Database/CliAuthLog/Schema.php
<paste — expected: type='char', length='64'>

$ grep -A2 "code_challenge'" includes/Database/CliAuthLog/Schema.php
<paste — expected: type='char', length='43'>

$ grep -A2 "access_token_hash" includes/Database/OAuthToken/Schema.php
<paste — expected: type='char', length='64'>
```

**Result**: [ ] PASS  [ ] FAIL

### 5. Summary & merge decision

| Gate | Status | Evidence link |
|---|---|---|
| T023 fresh-install smoke (SC-001) | [ ] PASS / [ ] FAIL | § 1 above |
| T025 phantom-guard manual (SC-004) | [ ] PASS / [ ] FAIL | § 2 above |
| PHPUnit Database suite (SC-005) | [ ] PASS / [ ] FAIL | § 3 above |
| FR-010 column widths (SEC-011-001) | [ ] PASS / [ ] FAIL | § 4 above |
| Whole-plugin PHPCS + PHPStan L8 on F011 files | [x] PASS (verified 2026-07-02 post-remediation) | tasks.md T037 |
| Pre-flight callers grep zero survivors | [x] PASS (verified 2026-07-02 workflow + follow-up) | `specs/011-berlindb-migration/pre-flight-callers.txt` |
| Memory-hub coherence (INDEX ↔ DECISIONS) | [x] PASS (verified 2026-07-02 post-remediation) | tasks.md T038, T040, T045 |

**Merge decision**: [ ] APPROVE  [ ] BLOCK — signature + date required when all 4 pending gates are green.
