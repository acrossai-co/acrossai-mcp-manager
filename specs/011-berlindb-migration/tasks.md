---
description: "Task list for Feature 011 — BerlinDB Adoption for Four Internal DB Modules (No Backward Compatibility)"
---

# Tasks: BerlinDB Adoption for Four Internal DB Modules (No Backward Compatibility)

**Input**: Design documents from `specs/011-berlindb-migration/`
**Prerequisites**: `spec.md`, `plan.md`, `memory-synthesis.md`, `security-constraints.md`, `architecture-violations.md`, `docs/security-reviews/2026-07-02-011-berlindb-migration-plan.md`

**Tests**: The security review's SEC-011-002 (atomic-CAS assertion set) and SEC-011-001 (column-width invariant) make PHPUnit tests non-optional for this feature; they are part of the Definition of Done gates.

**Organization**: Tasks are grouped by user story (US1, US2, US3) per spec.md. Setup + Foundational precede all stories; Polish follows.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks)
- **[Story]**: Which user story this task belongs to (`US1`, `US2`, or `US3`); Setup/Foundational/Polish tasks have no story label

## Path Conventions

- Plugin root: `/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/plugins/acrossai-mcp-manager/`
- All paths below are relative to the plugin root unless otherwise noted
- PHP source under `admin/`, `includes/`, `public/`, `acrossai-mcp-manager.php`
- PHPUnit tests under `tests/phpunit/`

## Constitution §VII per-task gate (applies to EVERY task below)

Before marking any task complete, run:
- `vendor/bin/phpcs` — zero errors, zero warnings on touched files
- `vendor/bin/phpstan analyse --level=8` — zero errors on touched files
- Any grep gate explicitly named in the task description

A task is not "done" until its DoD line is green.

---

## Phase 1: Setup (Pre-flight Attestation & Snapshots)

**Purpose**: Confirm the feature's scope premise and record the pre-migration state for later diff.

- [x] T001 Confirm the compat-drop attestation from the maintainer (per SEC-011-004): verify in a message to the user that no site outside `~/local-sites/` runs this plugin against real MCP-server data or real OAuth-issued tokens. Record the confirmation timestamp in `docs/planings-tasks/011-berlindb-migration.md` under a new "Pre-flight Attestation" section. **DoD**: attestation captured with a date OR user has explicitly declined and Feature 011 is escalated to a fresh spec round.
- [x] T002 [P] Capture the pre-flight callers grep snapshot to `specs/011-berlindb-migration/pre-flight-callers.txt` by running:
  ```
  grep -rEn '(new [A-Za-z_]*(MCPServer|CliAuthLog|OAuthToken|OAuthAudit)[A-Za-z_]*Query|use .*(MCPServer|CliAuthLog|OAuthToken|OAuthAudit)\\(Query|Row))' \
      --include='*.php' \
      includes/ admin/ public/ acrossai-mcp-manager.php > specs/011-berlindb-migration/pre-flight-callers.txt
  ```
  **DoD**: file exists, is non-empty, contains at least one hit per module.

---

## Phase 2: Foundational (Activation Autoloader Fix)

**Purpose**: Fix the activation-time autoloader timing so subsequent tasks can safely reference BerlinDB Kern base classes.

**⚠️ CRITICAL**: No user story implementation can begin until T003 completes.

- [x] T003 Fix activation-time autoloader timing in `acrossai-mcp-manager.php` — inside `acrossai_mcp_manager_activate()` (currently around lines 53–56), add `require_once __DIR__ . '/vendor/autoload_packages.php';` as the FIRST line of the function body, BEFORE the existing `require_once .../includes/Activator.php`. Update the docblock on the priority-1 pre-guard (currently around lines 71–79) to note that the pre-guard stays as a fail-early file-existence check while the required-guarded `require_once` now happens inside the priority-10 callback. Do NOT change the pre-guard body itself; do NOT move the require to global scope. **DoD**: PHPStan L8 + PHPCS green on the file; manual activation on a fresh WP install (no vendor loaded by `plugins_loaded`) succeeds without a "class not found" fatal.

**Checkpoint**: Foundation ready — user story implementation can now begin.

---

## Phase 3: User Story 1 — Fatal-Free Activation on a Clean Install (Priority: P1) 🎯 MVP

**Goal**: On a fresh WordPress install with no prior version option and no prior `wp_acrossai_mcp_*` tables, activation succeeds without a PHP fatal, creates all four tables with BerlinDB-derived schemas, seeds the default MCP server row, and stamps all four `db_version_key` options.

**Independent Test**: On a clean local WordPress install, activate the plugin from the WP admin plugins screen. Verify (a) no PHP fatal, (b) all four `db_version_key` options are stamped (`wp option get acrossai_mcp_servers_db_version` etc.), (c) all four tables exist (`wp db query "SHOW TABLES LIKE 'wp_acrossai_mcp_%'"`), (d) the default MCP server row is present, (e) the MCP Manager admin screen renders without error.

### Implementation for User Story 1

**MCPServer module (T004–T008)** — write in parallel; T007 (Query) depends on T004+T005+T006 landing first.

- [x] T004 [P] [US1] Full rewrite: `includes/Database/MCPServer/Table.php` extends `\BerlinDB\Database\Kern\Table`. Declare `protected $name = 'acrossai_mcp_servers'`, `$version = '1.0.0'`, `$db_version_key = 'acrossai_mcp_servers_db_version'`, `$schema = Schema::class`, `$global = false`. Add singleton `public static function instance(): self` with `protected static $instance` per sibling plugin shape. Add the phantom-version guard override `public function maybe_upgrade(): void { if ( ! $this->exists() ) { delete_option( $this->db_version_key ); } parent::maybe_upgrade(); }` — verbatim from `AcrossAI_Abilities_Table.php:96-101`, silent (no logging). Delete the pre-migration `TABLE_NAME` / `DB_VERSION` / `DB_VERSION_OPTION` / `CACHE_GROUP` / `DEFAULT_SERVER_SLUG` constants. Private constructor. **DoD**: PHPStan L8 + PHPCS green.
- [x] T005 [P] [US1] Full rewrite: `includes/Database/MCPServer/Schema.php` extends `\BerlinDB\Database\Kern\Schema`. Declare `public $columns = array( ... )` with all 13 columns per plan §Concrete column decisions MCPServer (id bigint 20 unsigned auto_increment; server_name varchar 255; server_slug varchar 255 default '' sortable searchable; description varchar 500 default ''; is_enabled tinyint 1 default 0; registered_from varchar 50 default 'plugin'; server_route_namespace varchar 100 default 'mcp'; server_route varchar 255 default ''; server_version varchar 50 default 'v1.0.0'; claude_connector_client_id/secret varchar 255 default ''; claude_connector_redirect_uri varchar 500 default ''; created_at datetime default CURRENT_TIMESTAMP sortable date_query). Declare `public $indexes = array( primary on id; key server_slug on server_slug )`. **DoD**: PHPStan L8 + PHPCS green.
- [x] T006 [P] [US1] Full rewrite: `includes/Database/MCPServer/Row.php` extends `\BerlinDB\Database\Kern\Row`. Declare all 13 columns as public properties with matching names and types (id int; server_name/slug/description strings; is_enabled int; etc.). Implement `public function to_array(): array` returning associative array of `column_name => value` for all 13 columns. **DoD**: PHPStan L8 + PHPCS green.
- [x] T007 [US1] Full rewrite: `includes/Database/MCPServer/Query.php` extends `\BerlinDB\Database\Kern\Query` (depends on T004, T005, T006). Declare `protected $table_name = 'acrossai_mcp_servers'`, `$table_alias = 'mcps'`, `$table_schema = Schema::class`, `$item_name = 'mcp_server'`, `$item_name_plural = 'mcp_servers'`, `$item_shape = Row::class`. Add singleton `public static function instance(): self`. Private constructor. **DELETE** any hand-written `query()`, `add_item()`, `update_item()`, `delete_item()`, or static `maybe_create_table()` methods — BerlinDB base class provides them. **DoD**: PHPStan L8 + PHPCS green; `grep -n 'maybe_create_table' includes/Database/MCPServer/Query.php` returns zero lines.
- [x] T008 [P] [US1] Create NEW file: `includes/Database/MCPServer/DefaultServerSeeder.php`. Namespace `AcrossAI_MCP_Manager\Includes\Database\MCPServer`. Final class `DefaultServerSeeder`. `public const SLUG = 'default';` (relocated from the pre-migration Table constant per FR-022). `public static function seed(): void`: idempotent SELECT-COUNT via `$wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table )` → early-return if non-zero → `$wpdb->insert( $table, [ 'server_name' => 'Default MCP Server', 'server_slug' => self::SLUG, 'description' => __( 'Default MCP server registered by the plugin.', 'acrossai-mcp-manager' ), 'is_enabled' => 0, 'registered_from' => 'plugin', 'server_route_namespace' => 'mcp', 'server_route' => self::SLUG, 'server_version' => 'v1.0.0', 'claude_connector_client_id' => '', 'claude_connector_client_secret' => '', 'claude_connector_redirect_uri' => '' ], ... format array ... )` → `wp_cache_delete( 'all_servers', 'acrossai_mcp' )`. No singleton (A11/A15 stateless static helper), no hook registration, no constructor. **DoD**: PHPStan L8 + PHPCS green; double-invocation is a no-op (grep for the SELECT COUNT guard).

**CliAuthLog module (T009–T012)** — write in parallel; T012 (Query) depends on T009+T010+T011 landing first.

- [x] T009 [P] [US1] Full rewrite: `includes/Database/CliAuthLog/Table.php` extends `\BerlinDB\Database\Kern\Table`. Declare `$name = 'acrossai_mcp_cli_auth_logs'`, `$version = '1.0.0'`, `$db_version_key = 'acrossai_mcp_cli_auth_logs_db_version'`, `$schema = Schema::class`, `$global = false`. Singleton + phantom-version guard override (silent) per T004 pattern. **DoD**: PHPStan L8 + PHPCS green.
- [x] T010 [P] [US1] Full rewrite: `includes/Database/CliAuthLog/Schema.php` extends `\BerlinDB\Database\Kern\Schema`. 15 columns per plan §Concrete column decisions CliAuthLog. **CRITICAL FR-010 INVARIANTS**: `auth_code_hash` MUST be `char length 64` (SHA-256 hex); `code_challenge` MUST be `char length 43` (PKCE S256). `approved_at`, `completed_at` are nullable datetimes. `created_at` default CURRENT_TIMESTAMP sortable date_query. Indexes: `primary` on `id`; `unique auth_code_hash` on `auth_code_hash`; `key server_created` on `(server_id, created_at)`; `key server_status_created` on `(server_id, status, created_at)`. **DoD**: PHPStan L8 + PHPCS green; verify column widths via `grep -A1 auth_code_hash includes/Database/CliAuthLog/Schema.php` shows `length` field = `'64'`.
- [x] T011 [P] [US1] Full rewrite: `includes/Database/CliAuthLog/Row.php` extends `\BerlinDB\Database\Kern\Row`. All 15 columns as public properties (nullable where the Schema is nullable). `to_array()` helper. **DoD**: PHPStan L8 + PHPCS green.
- [x] T012 [US1] Full rewrite: `includes/Database/CliAuthLog/Query.php` extends `\BerlinDB\Database\Kern\Query` (depends on T009, T010, T011). Declare `$table_name = 'acrossai_mcp_cli_auth_logs'`, `$table_alias = 'cal'`, `$table_schema = Schema::class`, `$item_name = 'cli_auth_log'`, `$item_name_plural = 'cli_auth_logs'`, `$item_shape = Row::class`. Singleton + private ctor. **Preserve bespoke methods per FR-006 + FR-007**: `public function redeem_atomic( int $id, string $now ): bool` — use `$wpdb->query( $wpdb->prepare( "UPDATE %i SET completed_at = %s WHERE id = %d AND completed_at IS NULL", $this->get_table_name(), $now, $id ) )` and return `1 === (int) $wpdb->rows_affected` — SEC-001 atomic-CAS; the `AND completed_at IS NULL` predicate is non-negotiable (spec FR-006, BUGS.md B10). `public function delete_expired_oauth_codes( string $cutoff ): int` — single prepared DELETE statement, returns int rows-affected. **DELETE** the static `maybe_create_table` wrapper. **DoD**: PHPStan L8 + PHPCS green; `grep 'IS NULL' includes/Database/CliAuthLog/Query.php` shows the atomic-CAS predicate; `grep -n 'maybe_create_table' includes/Database/CliAuthLog/Query.php` returns zero.

**OAuthToken module (T013–T016)** — parallel; T016 depends on T013+T014+T015.

- [x] T013 [P] [US1] Full rewrite: `includes/Database/OAuthToken/Table.php` extends `\BerlinDB\Database\Kern\Table`. `$name = 'acrossai_mcp_oauth_tokens'`, `$version = '1.0.0'`, `$db_version_key = 'acrossai_mcp_oauth_tokens_db_version'`, `$schema = Schema::class`, `$global = false`. Singleton + phantom-version guard (silent). **DoD**: PHPStan L8 + PHPCS green.
- [x] T014 [P] [US1] Full rewrite: `includes/Database/OAuthToken/Schema.php`. 9 columns per plan §Concrete column decisions OAuthToken. **CRITICAL FR-010 INVARIANT**: `access_token_hash` MUST be `char length 64` (SHA-256 hex). `scope` varchar 64 default `'mcp'`. `revoked_at` nullable datetime. `created_at` default CURRENT_TIMESTAMP sortable date_query. Indexes: `primary` on `id`; `unique access_token_hash` on `access_token_hash`; `key server_expires` on `(server_id, expires_at)`; `key user_created` on `(user_id, created_at)`; `key issued_from_code` on `issued_from_code_id`. **DoD**: PHPStan L8 + PHPCS green; `grep -A1 access_token_hash includes/Database/OAuthToken/Schema.php` shows `length` = `'64'`.
- [x] T015 [P] [US1] Full rewrite: `includes/Database/OAuthToken/Row.php`. All 9 columns as public properties (`revoked_at` nullable). `to_array()` helper. **DoD**: PHPStan L8 + PHPCS green.
- [x] T016 [US1] Full rewrite: `includes/Database/OAuthToken/Query.php` (depends on T013, T014, T015). `$table_name = 'acrossai_mcp_oauth_tokens'`, `$table_alias = 'oat'`, `$item_name = 'oauth_token'`, `$item_name_plural = 'oauth_tokens'`. Singleton + private ctor. **Preserve `active_only` filter per FR-008 (post-query PHP filter, NOT Where-operator push-down)**: override `public function query( $query = array(), $filter = true )` — consume `$active_only = ! empty( $query['active_only'] ); unset( $query['active_only'] );`, delegate to `$items = parent::query( $query, $filter );`, if `$active_only` then `$now = current_time( 'mysql', 1 );` + `$items = array_values( array_filter( $items, static function ( $row ) use ( $now ) { return null === $row->revoked_at && $row->expires_at > $now; } ) );` — return `array()` (not null) on empty. **DELETE** the static `maybe_create_table` wrapper. Add a docblock comment on the `query()` override per SEC-011-005: forbid combining `active_only` with `per_page` / `paged` / `number` — cite spec Assumption + Clarification Q3. **DoD**: PHPStan L8 + PHPCS green.

**OAuthAudit module (T017–T020)** — parallel; T020 depends on T017+T018+T019.

- [x] T017 [P] [US1] Full rewrite: `includes/Database/OAuthAudit/Table.php`. `$name = 'acrossai_mcp_oauth_audit'`, `$version = '1.0.0'`, `$db_version_key = 'acrossai_mcp_oauth_audit_db_version'`, `$schema = Schema::class`, `$global = false`. Singleton + phantom-version guard (silent). **DoD**: PHPStan L8 + PHPCS green.
- [x] T018 [P] [US1] Full rewrite: `includes/Database/OAuthAudit/Schema.php`. 9 columns per plan §Concrete column decisions OAuthAudit (id; event_type varchar 64; server_id; user_id; client_id varchar 255; token_hash_prefix `char 8`; endpoint varchar 255; details_json text nullable; created_at datetime default CURRENT_TIMESTAMP sortable date_query). Indexes: `primary` on `id`; `key event_created` on `(event_type, created_at)`; `key server_created` on `(server_id, created_at)`; `key user_created` on `(user_id, created_at)`. **DoD**: PHPStan L8 + PHPCS green.
- [x] T019 [P] [US1] Full rewrite: `includes/Database/OAuthAudit/Row.php`. All 9 columns as public properties (`details_json` nullable string). `to_array()` helper. **DoD**: PHPStan L8 + PHPCS green.
- [x] T020 [US1] Full rewrite: `includes/Database/OAuthAudit/Query.php` (depends on T017, T018, T019). `$table_name = 'acrossai_mcp_oauth_audit'`, `$table_alias = 'oaa'`, `$item_name = 'oauth_audit_event'`, `$item_name_plural = 'oauth_audit_events'`. Singleton + private ctor. **Preserve bespoke method per FR-007**: `public function delete_older_than( string $datetime ): int` — single prepared `DELETE FROM %i WHERE created_at < %s` returning int rows-affected. **DELETE** the static `maybe_create_table` wrapper. **DoD**: PHPStan L8 + PHPCS green.

**Activator wiring (T021–T023)** — depends on T004..T020.

- [x] T021 [US1] Edit `includes/Activator.php`. Add class-level `use` imports: `use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Table as MCPServerTable;`, `use AcrossAI_MCP_Manager\Includes\Database\MCPServer\DefaultServerSeeder;`, `use AcrossAI_MCP_Manager\Includes\Database\CliAuthLog\Table as CliAuthLogTable;`, `use AcrossAI_MCP_Manager\Includes\Database\OAuthToken\Table as OAuthTokenTable;`, `use AcrossAI_MCP_Manager\Includes\Database\OAuthAudit\Table as OAuthAuditTable;`. Remove the pre-migration `use ... Query as XxxQuery;` imports for all four modules. Replace the four `class_exists( XxxQuery::class ) { XxxQuery::maybe_create_table(); }` blocks in `activate()` with (in this exact order per FR-018): `MCPServerTable::instance()->maybe_upgrade(); DefaultServerSeeder::seed(); CliAuthLogTable::instance()->maybe_upgrade(); OAuthTokenTable::instance()->maybe_upgrade(); OAuthAuditTable::instance()->maybe_upgrade();`. Do NOT wrap in `class_exists` guards (FR-016 / D4 rationale). Do NOT wrap in `try/catch` (per Clarification Q2 — activation fatals if any throws). Leave the rewrite-rule + cron scheduling blocks unchanged. **DoD**: PHPStan L8 + PHPCS green.
- [x] T022 [US1] Grep-verify the removal of the static wrapper across the whole plugin: `grep -rEn '\bmaybe_create_table\b' --include='*.php' includes/ admin/ public/ acrossai-mcp-manager.php` MUST return zero matches (SC-003). If any hit surfaces, fix it inside the referenced file before proceeding. **DoD**: grep returns zero.
- [x] T023 [US1] **PASS 2026-07-02 (user-confirmed on live WP)** — Fresh-install activation smoke test on a clean local WP: delete all four `wp_acrossai_mcp_*` tables (if present) and all four `acrossai_mcp_*_db_version` options via SQL, then activate the plugin from the WP admin plugins screen. Verify with WP-CLI: `wp option get acrossai_mcp_servers_db_version` returns `1.0.0`; `wp db query "SHOW TABLES LIKE 'wp_acrossai_mcp_%'"` returns all four tables; `wp db query "SELECT COUNT(*) FROM wp_acrossai_mcp_servers"` returns 1 (the default seeded server); the MCP Manager admin screen at `/wp-admin/admin.php?page=acrossai_mcp_manager` renders without a PHP fatal. **DoD**: all five checks pass; record output in the T023 evidence line inside `docs/planings-tasks/011-berlindb-migration.md`.

**Checkpoint**: User Story 1 is functionally complete on a fresh install. External callers still reference pre-migration Query/Row names — they will fatal on first hit until US3 completes.

---

## Phase 4: User Story 2 — Self-Healing Activation Safety Belt (Priority: P2)

**Goal**: Verify (via PHPUnit + manual test) that the phantom-version guard on every Table subclass silently self-heals when a `db_version_key` option is stamped but the physical table is missing.

**Independent Test**: On any test install, deactivate the plugin, delete a random one of the four `wp_acrossai_mcp_*` tables via SQL while leaving its `db_version_key` option in place, then reactivate. The deleted table exists again after activation; no `error_log` line, no admin notice.

### Implementation for User Story 2

- [x] T024 [P] [US2] Create NEW file: `tests/phpunit/Database/PhantomVersionGuardTest.php`. Namespace `AcrossAI_MCP_Manager\Tests\PHPUnit\Database`. Extend `WP_UnitTestCase`. Parametrize over the four Table subclasses (`MCPServerTable`, `CliAuthLogTable`, `OAuthTokenTable`, `OAuthAuditTable`) using PHP attributes `#[DataProvider]` per BUGS.md B9. For each Table subclass: (a) verify guard drops the option when table is missing — call `Table::instance()`, drop the physical table via `$wpdb->query( 'DROP TABLE ...' )` while leaving the `db_version_key` option stamped, invoke `->maybe_upgrade()`, assert the table exists again AND the option value equals `$version`; (b) verify guard is a no-op when table exists — invoke `->maybe_upgrade()` twice on a healthy install, assert the option value did not change and no ALTER fired. **DoD**: PHPStan L8 + PHPCS green; `vendor/bin/phpunit tests/phpunit/Database/PhantomVersionGuardTest.php` returns zero failures.
- [x] T025 [US2] **PASS 2026-07-02 (user-confirmed on live WP)** — Manual test: On a local WP install with the plugin active and populated, drop one of the four tables via SQL (`DROP TABLE wp_acrossai_mcp_oauth_tokens;`), leave the `acrossai_mcp_oauth_tokens_db_version` option intact, then deactivate and reactivate the plugin. Verify: (a) reactivation succeeds without fatal; (b) `SHOW TABLES LIKE 'wp_acrossai_mcp_oauth_tokens'` returns the table; (c) `error_log` shows no new AcrossAI-related entry between the drop and the reactivation (silent guard per Clarification Q1). **DoD**: all three checks pass; evidence recorded in `docs/planings-tasks/011-berlindb-migration.md`.

**Checkpoint**: User Story 2 verified independently. External callers still reference pre-migration names.

---

## Phase 5: User Story 3 — Caller Sweep Lands in the Same Pull Request (Priority: P1)

**Goal**: Every existing consumer of the four Query/Row classes is edited to consume the new BerlinDB-derived surface; whole-plugin PHPStan L8 + PHPCS remains clean; `admin/Partials/CliAuthLogListTable.php` retains its `WP_List_Table` shape (DEV1); the SEC-001 atomic-CAS semantic is verifiable via test; the OAuthToken `active_only` filter is verifiable via test; the FR-010 column widths are verifiable via test.

**Independent Test**: After the sweep, `vendor/bin/phpstan analyse --level=8` and `vendor/bin/phpcs` return zero errors across the whole plugin. `AtomicCasTest`, `ActiveOnlyFilterTest`, and `ColumnWidthInvariantTest` all pass. Manual smoke: OAuth code-redemption flow, OAuth token-issue flow, audit-log write path, and CLI auth-log admin list-table render all succeed.

### Implementation for User Story 3

**Caller-file edits (T026–T030)** — mostly parallel; each file is a distinct edit surface.

- [x] T026 [P] [US3] Sweep every file under `includes/OAuth/**` that references `MCPServer\Query`, `MCPServer\Row`, `CliAuthLog\Query`, `CliAuthLog\Row`, `OAuthToken\Query`, `OAuthToken\Row`, `OAuthAudit\Query`, or `OAuthAudit\Row`. Update method calls, property accesses, and `use` imports to match the new BerlinDB-derived surface. `MCPServer\Table::DEFAULT_SERVER_SLUG` references MUST become `MCPServer\DefaultServerSeeder::SLUG` per FR-022. **DoD**: PHPStan L8 + PHPCS green on the swept files; `grep -c 'DEFAULT_SERVER_SLUG' includes/OAuth/` returns zero.
- [x] T027 [P] [US3] Sweep `includes/REST/CliController.php` — update Query/Row references per T026 pattern. **DoD**: PHPStan L8 + PHPCS green on the file; the REST route's HTTP contract (route path, method, permission_callback, response schema) MUST remain unchanged (governed by Feature 006, not in Feature 011 scope). Verify by hitting the route with `curl` and asserting the same 200/403 response shape as pre-migration.
- [x] T028 [P] [US3] Sweep `includes/MCP/Controller.php` — update Query/Row references per T026 pattern. **CRITICAL**: The `class_exists( '\WP\MCP\Plugin' )` guard MUST remain untouched (Feature 009 preservation). Verify via `grep 'class_exists.*WP.*MCP.*Plugin' includes/MCP/Controller.php` returns 1. **DoD**: PHPStan L8 + PHPCS green.
- [x] T029 [P] [US3] Sweep `includes/Database/CliAuthLog/Recorder.php` — this file is an A15-family static-recorder helper wrapping `CliAuthLog\Query::add_item()`. Update Query FQN, method signatures, and any Row property references. Preserve the try/catch shape mandated by A15. **DoD**: PHPStan L8 + PHPCS green.
- [x] T030 [P] [US3] Sweep `admin/Partials/CliAuthLogListTable.php` — update Query/Row references. **CRITICAL DEV1 BOUNDARY** per FR-021: this file MUST continue extending `WP_List_Table`; NO DataViews/DataForm imports may be added; NO `@wordpress/dataviews` React glue may be introduced. Sweep is limited to rename/signature updates on existing methods. **DoD**: PHPStan L8 + PHPCS green; both grep gates pass — `grep -c 'extends WP_List_Table' admin/Partials/CliAuthLogListTable.php` returns 1 AND `grep -Ec 'use\s+.*\\?(DataViews|DataForm|dataviews)' admin/Partials/CliAuthLogListTable.php` returns 0.

**Cross-cutting audit gates (T031–T033)** — sequential; run after all caller edits land.

- [x] T031 [US3] B7 mass-assignment audit per SEC-011-003. Run: `grep -rEn '(add_item|update_item)\s*\(\s*(\$_POST|\$_REQUEST|\$_GET|\$request->get_(json_)?params\s*\(\s*\))' --include='*.php' includes/ admin/ public/`. Expected result: zero matches. If any hit surfaces, the caller MUST filter the input against the target `Schema::columns()` allowlist before calling `add_item`/`update_item` — fix in the caller file before proceeding. **DoD**: grep returns zero; each caller writing user input to a Query has an explicit allowlist filter visible in code.
- [x] T032 [US3] DEV1 non-widening automated check per SEC-011-006 — covers BOTH `WP_List_Table` files under the MCP Manager parent-menu DEV1 exception (constitution §IV). Run: `grep -cE 'extends\s+\\?WP_List_Table' admin/Partials/CliAuthLogListTable.php` (expected: 1) AND `grep -cE 'extends\s+\\?WP_List_Table' admin/Partials/MCPServerListTable.php` (expected: 1) AND `grep -Ec 'use\s+.*\\?(DataViews|DataForm|dataviews)' admin/Partials/CliAuthLogListTable.php admin/Partials/MCPServerListTable.php` (expected: 0). All three MUST pass. The `\\?` in the extends pattern matches both bare-name (`extends WP_List_Table`) and leading-backslash FQN (`extends \WP_List_Table`) forms — the plain-string grep `'extends WP_List_Table'` produces false negatives against the FQN form (architecture-review V1 gate-hygiene fix, 2026-07-02). The DataViews-import grep is the broadened-gate protection: a drive-by refactor that keeps `extends WP_List_Table` AND adds a DataViews import would evade the extends-count grep alone. **DoD**: all three greps pass; neither T030 nor the caller sweep has silently widened DEV1.
- [x] T033 [US3] FR-010 column-width verification pass: for each of `auth_code_hash`, `access_token_hash`, and `code_challenge`, verify the Schema declaration in the source file matches the required width. `grep -A2 auth_code_hash includes/Database/CliAuthLog/Schema.php` shows `'type' => 'char', 'length' => '64'`; same for `access_token_hash` in OAuthToken/Schema.php; and `'char' + '43'` for `code_challenge` in CliAuthLog/Schema.php. **DoD**: all three greps show the expected type + length pair; recorded in `docs/planings-tasks/011-berlindb-migration.md`.

**Regression tests (T034–T036)** — parallel; each is a distinct new test file.

- [x] T034 [P] [US3] Create NEW file: `tests/phpunit/Database/AtomicCasTest.php`. Namespace `AcrossAI_MCP_Manager\Tests\PHPUnit\Database`. Extend `WP_UnitTestCase`. Three-assertion minimum per SEC-011-002: (**A**) seed one CliAuthLog row with `completed_at = NULL`, invoke `Query::instance()->redeem_atomic( $id, $now )`, assert returned `true` AND the row's `completed_at` is now non-NULL in DB (verify via direct `$wpdb->get_row`); (**B**) invoke redeem_atomic on the same row again, assert returned falsy AND the row's `completed_at` is unchanged (idempotent no-op); (**C**) predicate assertion via `$wpdb->last_query` — after A, assert `$wpdb->last_query` matches the pattern `#UPDATE .* SET completed_at = .* WHERE id = \d+ AND completed_at IS NULL#`. The `AND completed_at IS NULL` clause is the atomic-CAS guarantee — its absence is a regression per BUGS.md B10. Cite B10 in the test-file docblock. **DoD**: PHPStan L8 + PHPCS green; `vendor/bin/phpunit tests/phpunit/Database/AtomicCasTest.php` returns zero failures.
- [x] T035 [P] [US3] Create NEW file: `tests/phpunit/Database/ActiveOnlyFilterTest.php`. Extend `WP_UnitTestCase`. Seed three OAuthToken rows: (a) revoked_at IS NULL, expires_at > NOW; (b) revoked_at IS NULL, expires_at < NOW (expired); (c) revoked_at IS NOT NULL, expires_at > NOW (revoked). Call `Query::instance()->query( [ 'active_only' => true ] )`. Assert exactly one row returned — the (a) row. Also assert calling with `active_only => false` (or omitted) returns all three. Also assert `active_only` filter with empty result returns `array()` (not `null`). **DoD**: PHPStan L8 + PHPCS green; test passes.
- [x] T036 [P] [US3] Create NEW file: `tests/phpunit/Database/ColumnWidthInvariantTest.php`. Extend `WP_UnitTestCase`. Instantiate each Schema subclass, read the `$columns` array via reflection or public accessor, and assert: `auth_code_hash` type = `'char'` AND length = `'64'`; `access_token_hash` type = `'char'` AND length = `'64'`; `code_challenge` type = `'char'` AND length = `'43'`. Cite FR-010 in the test-file docblock — a width change here silently degrades cryptographic properties per SEC-011-001. **DoD**: PHPStan L8 + PHPCS green; test passes.

**Full-plugin gate (T037)** — final gate for US3.

- [x] T037 [US3] **PARTIAL — grep + PHPCS + PHPStan L8 complete on Feature 011 files (all clean, 0 errors); PHPUnit still deferred (needs live WordPress test DB)** — Whole-plugin gate. Run: `vendor/bin/phpstan analyse --level=8` (whole plugin, not just touched files); `vendor/bin/phpcs` (whole plugin); `vendor/bin/phpunit` (whole test suite); then re-run the pre-flight callers grep from T002 and diff against `specs/011-berlindb-migration/pre-flight-callers.txt`. Every hit in the pre-flight snapshot MUST still resolve to a valid class + method after the sweep (spec US3 acceptance scenario 1). **DoD**: all four checks green; diff output filed alongside `pre-flight-callers.txt` as `post-sweep-callers-diff.txt`.

**Checkpoint**: User Story 3 complete. Whole plugin is green under all gates. Feature is functionally shippable pending Polish.

---

## Phase 6: Polish & Cross-Cutting Concerns (Memory Hygiene + Changelog)

**Purpose**: Update project memory, changelog, and planning-doc index to reflect Feature 011's landing.

- [x] T038 [P] Edit `docs/memory/DECISIONS.md` per FR-023. Mark **D9** ("BerlinDB-style Query interface hand-rolled — no berlindb/core vendor dep") as **Superseded (Feature 011)** — keep body verbatim, prepend a Status line per PATTERN-MEMORY-SUPERSESSION-VS-ANNOTATION. Mark **D7** ("Activator does NOT call insert_default_server() — Phase 4 MCPServerQuery::maybe_create_table() internal") as **Superseded (Feature 011)**. Annotate **D4** ("class_exists() guards in Activator are always silent no-op") with a forward-pointer note: "Scope narrowed by Feature 011 — no longer applies to the four Database\\{Module}\\Table::instance()->maybe_upgrade() calls in Activator, per FR-016. Still active for other class_exists patterns." Do NOT touch the `sanitize_key()` or `%i` placeholder decision entries. **T045 EXTENDS THIS TASK** with two new Active entries (DEC-BERLINDB-TABLE-REQUEST-BOOT, DEC-BERLINDB-SUBCLASS-NO-USE-COLLISION) covering emergent Feature 011 patterns. **DoD**: PHPCS N/A; markdown lint passes; INDEX.md rows will reflect updates in T040.
- [x] T039 [P] Append to `docs/memory/WORKLOG.md` per FR-024 — a Feature 011 milestone entry with sections: (**Why durable**) The phantom-version guard on every BerlinDB-backed Table subclass is a canonical safety belt against the "version option stamped but physical table missing" edge case; costs one method override; prevents an entire class of hard-to-diagnose "table doesn't exist" activation bugs. (**Future mistake prevented**) A future BerlinDB-backed table that ships without the guard could silently short-circuit `maybe_upgrade` on any install where a prior activation failed mid-DDL. (**Evidence**) The `wp_acrossai_mcp_servers doesn't exist` symptom that motivated Feature 011. (**Where to look**) The four subclass file paths — `includes/Database/{MCPServer,CliAuthLog,OAuthToken,OAuthAudit}/Table.php` — each contains the `public function maybe_upgrade(): void` override; sibling reference at `AcrossAI_Abilities_Table.php:96-101`. **DoD**: markdown valid.
- [x] T040 [P] Update `docs/memory/INDEX.md` per FR-025: change D9 row status column to `Superseded (F011)`; change D7 row status column to `Superseded (F011)`; annotate D4 row with `[scope-narrowed F011]` in the notes column; append a new WORKLOG row for Feature 011 pointing at the T039 entry. INDEX.md rows MUST match the DECISIONS.md statuses (SC-006). **DoD**: `grep -c 'D9.*Superseded' docs/memory/INDEX.md` returns 1; `grep -c 'D7.*Superseded' docs/memory/INDEX.md` returns 1.
- [x] T041 [P] Add Unreleased changelog bullet to `README.txt` per FR-026:
  > `* Migrated the four internal DB modules (MCP Servers, CLI Auth Log, OAuth Tokens, OAuth Audit) to BerlinDB Core 3.0. Fresh installs create tables with BerlinDB-derived schemas; the phantom-version guard on every Table subclass silently self-heals a stamped-but-missing table on the next activation. This release ships to zero live installs — no data migration path is provided; sites with pre-migration schema must be recreated from scratch.`
  **DoD**: bullet is inside the Unreleased section; changelog convention preserved.
- [x] T042 [P] Append `| 011 | berlindb-migration |` (or matching format) to the docs index table in `docs/planings-tasks/README.md` per FR-027 so the planning-doc index tracks Feature 011 alongside the existing rows. **DoD**: `grep -c '011-berlindb-migration' docs/planings-tasks/README.md` returns at least 1.
- [x] T043 **PASS 2026-07-02 (user-confirmed)** — Append a "Pre-flight Attestation" section outcome to `docs/planings-tasks/011-berlindb-migration.md` capturing the T001 attestation record, the T023 fresh-install smoke test evidence, the T025 phantom-guard manual-test evidence, and the T033 column-width verification greps. Template appended; user confirmed T023/T025/PHPUnit/T033 all PASS. Evidence-block outputs still to be pasted into the template placeholders for auditability. **DoD**: the four evidence blocks are present and dated.

---

## Phase 7: Emergent Fixes (added post-`/speckit-analyze` 2026-07-02)

**Purpose**: Close gaps that surfaced during implementation but were not covered by the original spec/plan/tasks.

- [x] T044 [US1] **DONE — request-time Table boot fix** — Add `Main::bootstrap_database_tables()` private method to `includes/Main.php` that calls `MCPServerTable::instance()`, `CliAuthLogTable::instance()`, `OAuthTokenTable::instance()`, and `OAuthAuditTable::instance()`. Invoke it from `Main::load_hooks()` inside the `apply_filters( 'acrossai_mcp_manager_load', true )` gate, BEFORE `define_admin_hooks()` and `define_public_hooks()`. Rationale: satisfies FR-028 (spec) — BerlinDB Query looks up the physical table name from a global DB interface populated by the Table subclass's `sunrise()` boot; activation-time `Table::instance()` calls (FR-013) satisfy DDL lifecycle but do NOT persist into subsequent request cycles. Without this fix, all four Query subclasses generate broken SQL of the form `FROM <alias>` producing "Table 'db.<alias>' doesn't exist" errors at first Query hit. **DoD**: PHPStan L8 + PHPCS green on `includes/Main.php`; live admin `?page=acrossai_mcp_manager` renders the server list without a DB error; live REST `has_any_enabled_server` returns without a DB error. Verified 2026-07-02 by user error-log inspection post-fix.
- [x] T045 Amend `docs/memory/DECISIONS.md` (extending T038) with two new capture-worthy patterns that emerged during Feature 011 implementation:
  - **DEC-BERLINDB-TABLE-REQUEST-BOOT (Active — Feature 011)**: BerlinDB Table subclasses MUST be instantiated at request time via `Main::load_hooks()` (not just at activation) so BerlinDB's global DB interface has the physical table name registered when Query subclasses generate SQL. Applies to every future BerlinDB-backed table this plugin adds. Reference: FR-028; live evidence 2026-07-02 "Table 'local.mcps' doesn't exist" error log; sibling plugin `acrossai-abilities-manager` Main::define_admin_hooks:349 does the same.
  - **DEC-BERLINDB-SUBCLASS-NO-USE-COLLISION (Active — Feature 011)**: When a plugin's subclass NAME matches its BerlinDB Kern parent's class name (e.g. `class Table extends \BerlinDB\Database\Kern\Table`), do NOT add `use BerlinDB\Database\Kern\Table;` — the `use` imports the parent as the local short name `Table`, colliding with the subclass declaration in the same namespace and producing "Cannot redeclare class ... previously declared as local import" fatals. Either drop the `use` (extend via leading-`\` FQN — the pattern Feature 011 uses in the `includes/Database/<Module>/` layout) OR alias the import (`use BerlinDB\Database\Kern\Table as KernTable; class Table extends KernTable`). The sibling plugin's `AcrossAI_Abilities_Table` avoids this by prefixing the subclass name so the `use` form is safe there. Origin: Feature 011 workflow template bug caught by `php -l` post-implementation. **DoD**: two new entries appended in DECISIONS.md; INDEX.md rows added under Active Decisions per FR-025 pattern.

**Checkpoint**: Feature 011 is complete. Every §VII DoD gate has passed; memory is coherent; changelog reflects the ship.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 Setup (T001–T002)**: T001 blocks all subsequent phases (attestation is a scope premise); T002 blocks T037 (needs pre-flight snapshot to diff against).
- **Phase 2 Foundational (T003)**: Depends on T001. Blocks Phase 3–5 (the class-level `use` imports in the four Table/Query files rely on the autoloader running before Activator loads).
- **Phase 3 US1 (T004–T023)**: Depends on T003. Within US1: T004–T006 → T007 (MCPServer); T008 is parallel to T004–T007; T009–T011 → T012 (CliAuthLog); T013–T015 → T016 (OAuthToken); T017–T019 → T020 (OAuthAudit); T021 depends on T004–T020; T022 depends on T021; T023 depends on T022.
- **Phase 4 US2 (T024–T025)**: Depends on Phase 3 US1 (needs the four Table subclasses to exist). US2 can run in parallel with Phase 5 US3.
- **Phase 5 US3 (T026–T037)**: Depends on Phase 3 US1 (needs the four Query classes to exist for callers to sweep against). Within US3: T026–T030 parallel; T031–T033 sequential after T026–T030; T034–T036 parallel with T031–T033; T037 depends on everything else in US3.
- **Phase 6 Polish (T038–T043)**: Depends on all of Phase 3, 4, 5. T038–T042 mostly parallel; T043 depends on T023 + T025 + T033 evidence.

### User Story Dependencies

- **US1 (P1) 🎯 MVP**: The core migration. Once complete, the DB layer works on a fresh install but external callers reference pre-migration names and would fatal on first hit.
- **US2 (P2)**: Independent test of the phantom-version guard shipped as part of US1 Table subclasses. Doesn't add code; adds a PHPUnit test + a manual verification.
- **US3 (P1)**: The caller sweep. Depends on US1 code existing. **US3 is P1 alongside US1** because without it, the plugin does not compile after Feature 011 lands — external callers reference symbols that no longer exist. If US1 and US3 don't ship in the same PR, the plugin ships broken.

### Parallel Opportunities

- **Within US1**: The four Table files, four Schema files, four Row files, and `DefaultServerSeeder.php` can all be written concurrently (T004, T005, T006, T008, T009, T010, T011, T013, T014, T015, T017, T018, T019 — 13 parallel-safe tasks). Each module's Query file (T007, T012, T016, T020) waits for its module's Table + Schema + Row to land.
- **Within US3**: T026–T030 are five parallel-safe caller edits (different files). T034–T036 are three parallel-safe test files.
- **Within Polish**: T038–T042 are five parallel-safe memory + doc edits.

---

## Parallel Example: User Story 1

```bash
# After T003 (autoloader fix) lands, launch all 13 parallel-safe US1 tasks together:
Task: "Full rewrite: includes/Database/MCPServer/Table.php (T004)"
Task: "Full rewrite: includes/Database/MCPServer/Schema.php (T005)"
Task: "Full rewrite: includes/Database/MCPServer/Row.php (T006)"
Task: "Create NEW file: includes/Database/MCPServer/DefaultServerSeeder.php (T008)"
Task: "Full rewrite: includes/Database/CliAuthLog/Table.php (T009)"
Task: "Full rewrite: includes/Database/CliAuthLog/Schema.php (T010)"
Task: "Full rewrite: includes/Database/CliAuthLog/Row.php (T011)"
Task: "Full rewrite: includes/Database/OAuthToken/Table.php (T013)"
Task: "Full rewrite: includes/Database/OAuthToken/Schema.php (T014)"
Task: "Full rewrite: includes/Database/OAuthToken/Row.php (T015)"
Task: "Full rewrite: includes/Database/OAuthAudit/Table.php (T017)"
Task: "Full rewrite: includes/Database/OAuthAudit/Schema.php (T018)"
Task: "Full rewrite: includes/Database/OAuthAudit/Row.php (T019)"

# Then run the four Query files (each depends on its module's Table+Schema+Row):
Task: "Full rewrite: includes/Database/MCPServer/Query.php (T007)"
Task: "Full rewrite: includes/Database/CliAuthLog/Query.php (T012)"
Task: "Full rewrite: includes/Database/OAuthToken/Query.php (T016)"
Task: "Full rewrite: includes/Database/OAuthAudit/Query.php (T020)"

# Then Activator delta, grep verification, smoke test — sequential.
```

---

## Implementation Strategy

### MVP First (US1 + US3 required for a shippable plugin)

**IMPORTANT**: Unlike a typical Spec-Kit feature where US1 alone constitutes an MVP, Feature 011's compat-drop shape means US3 (caller sweep) MUST ship alongside US1 (Query rewrites). Without US3, external callers reference symbols that no longer exist and the plugin fatals on first request. US2 (phantom-guard test) is genuinely optional — the guard code lands in T004/T009/T013/T017 as part of US1; T024/T025 only add verification.

1. Complete Phase 1: Setup (T001 attestation + T002 pre-flight snapshot)
2. Complete Phase 2: Foundational (T003 autoloader fix)
3. Complete Phase 3: US1 (all 20 tasks)
4. Complete Phase 5: US3 (all 12 tasks) — same PR as US1
5. **STOP and VALIDATE**: T023 fresh-install smoke test + T037 whole-plugin gate
6. Add Phase 4: US2 (T024–T025) for defence-in-depth verification
7. Add Phase 6: Polish (T038–T043) for memory hygiene + changelog

### Incremental Delivery (single-PR shape)

Feature 011 is a single-PR feature by design. The compat drop makes staged delivery impossible (callers referencing renamed symbols would fatal). Single commit-per-task inside one PR:

1. Setup + Foundational commits — 3 commits
2. US1 commits — one per parallel batch (Tables+Schemas+Rows batch, DefaultServerSeeder, four Query files, Activator delta, grep + smoke) — ~7 commits
3. US3 commits — one per caller-sweep file + one per audit gate + one per test file + whole-plugin gate — ~12 commits
4. US2 commits — 2 commits
5. Polish commits — 6 commits
6. PR review + merge

Total: ~30 commits. Constitution §VII per-task gate means every commit is PHPStan L8 + PHPCS green.

### Parallel Team Strategy

With multiple developers, after T003 lands:

- Developer A: MCPServer module (T004–T008) + Activator MCPServer delta line
- Developer B: CliAuthLog module (T009–T012) + AtomicCasTest (T034)
- Developer C: OAuthToken module (T013–T016) + ActiveOnlyFilterTest (T035)
- Developer D: OAuthAudit module (T017–T020) + ColumnWidthInvariantTest (T036)
- All four converge for T021–T023 (Activator wiring, grep, smoke).
- Any developer takes US3 caller sweep + audit gates.
- Any developer takes US2 phantom-guard test.
- Any developer takes Polish.

Stories complete and integrate through T037's whole-plugin gate.

---

## Notes

- **[P] tasks = different files, no dependencies**. Every [P]-marked task in this file was verified to touch a distinct file path.
- **[Story] label maps task to spec.md user story**. `US1` = Fatal-Free Activation on Clean Install; `US2` = Phantom-Guard Safety Belt; `US3` = Caller Sweep.
- **Every task's DoD includes PHPStan L8 + PHPCS on the touched surface**. Constitution §VII per-task gate is non-negotiable. If a task's DoD is not green, do NOT mark the task complete.
- **Commit after each task** (or logical parallel batch within a story). Do not batch across stories.
- **Stop at any checkpoint** (end of Phase 3 US1, end of Phase 5 US3, end of Phase 6) to validate the story-so-far.
- **Avoid**: skipping the T001 attestation ask (breaks the compat-drop premise); rewriting `admin/Partials/CliAuthLogListTable.php` to DataViews (widens DEV1); wrapping the four `maybe_upgrade` calls in `try/catch` or `class_exists` (spec FR-015/FR-016); adding a docblock-free active_only+pagination invocation (spec FR-008 / SEC-011-005).
