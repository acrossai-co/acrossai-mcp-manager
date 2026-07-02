# Feature Specification: BerlinDB Adoption for Four Internal DB Modules (No Backward Compatibility)

**Feature Branch**: `011-berlindb-migration`
**Created**: 2026-07-02
**Status**: Draft
**Input**: User description: "Migrate the four DB modules in `includes/Database/` (MCPServer, CliAuthLog, OAuthToken, OAuthAudit) to extend BerlinDB Core 3.0 base classes. No backward compatibility is preserved — the plugin has zero live installs, so it is free to rename tables and `db_version_key` option keys, restructure columns and indexes to the sibling plugin's conventions, change the public Query API surface, change Row public-property names, and edit external callers under `includes/OAuth/**`, `includes/REST/`, `includes/MCP/`, `includes/Database/CliAuthLog/Recorder.php`, and `admin/Partials/CliAuthLogListTable.php` in the same feature branch. Move MCPServer's default-server seeding out of the Query class into a new `DefaultServerSeeder::seed()` helper. Replace the four `class_exists+Query::maybe_create_table()` blocks in `Activator::activate()` with direct `Table::instance()->maybe_upgrade()` calls; delete the static `Query::maybe_create_table()` wrapper methods. Fix activation-time autoloader timing by requiring `vendor/autoload_packages.php` inside `acrossai_mcp_manager_activate()` before requiring the Activator. Add the phantom-version guard on every Table subclass as a safety belt for future installs even though no phantom-version option exists today. Preserve the SEC-001 atomic-CAS predicate semantics for one-shot CLI auth-code redemption and the SHA-256-hashed column semantics for auth codes and access tokens, even if the surrounding method or column names change. `admin/Partials/CliAuthLogListTable.php` retains its `WP_List_Table` shape (DEV1 exception) — sweep edits are limited to renames/signature updates; drive-by DataViews conversion is out of scope."

## Clarifications

### Session 2026-07-02

- Q: When the phantom-version guard fires (missing physical table, stale option deleted), should it record any diagnostic signal? → A: Silent — no log line, no admin notice; match the sibling plugin's canonical guard verbatim.
- Q: If one of the four `Table::instance()->maybe_upgrade()` calls throws during `Activator::activate()`, what should the Activator do? → A: Propagate — no `try/catch`; activation fatals with the underlying exception.
- Q: Which implementation strategy MUST the OAuthToken `active_only` filter use? → A: Post-query PHP `array_filter()` on the returned Row set (not a BerlinDB Where-operator push-down). Applies whether or not the filter is renamed as part of the caller sweep.
- Q: Should Feature 011 preserve backward compatibility? → A: No. Zero live installs; free to rename tables, options, columns, indexes, API surface, and edit callers under `includes/OAuth/`, `includes/REST/`, `includes/MCP/`, `includes/Database/CliAuthLog/Recorder.php`, and `admin/Partials/CliAuthLogListTable.php`. DEV1 `WP_List_Table` exception in the last file MUST NOT be widened.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Fatal-Free Activation on a Clean Install (Priority: P1)

A site administrator activates the AcrossAI MCP Manager plugin on a fresh WordPress install with no prior version option, no prior `wp_acrossai_mcp_*` tables, and no vendor autoloader yet registered by `plugins_loaded` (which has not fired at the moment the activation callback runs). Activation must succeed without a PHP fatal, create all four tables, seed the default MCP server row, and stamp the four `db_version_key` options — even though the four Query classes now extend BerlinDB Kern base classes that require the composer autoloader to be live at class resolution time.

**Why this priority**: This is the concrete blocker that would prevent Feature 011 from working on a fresh install at all. Every subsequent user story depends on activation succeeding.

**Independent Test**: On a clean local WordPress install (no `acrossai_mcp_*_db_version` options, no `wp_acrossai_mcp_*` tables), activate the plugin from the WP admin plugins screen. Verify (a) no PHP fatal, (b) all four `db_version_key` options are stamped, (c) all four tables exist, (d) the default MCP server row is present, (e) the MCP Manager admin screen renders without error.

**Acceptance Scenarios**:

1. **Given** a fresh install with no vendor autoloader yet registered by `plugins_loaded`, **When** the site admin activates the plugin, **Then** the activation callback loads the vendor autoloader before loading the Activator, and no "class not found" fatal occurs.
2. **Given** all four tables and options are absent, **When** activation runs, **Then** all four tables are created with the intended BerlinDB-derived schema, the default MCP server row is seeded, and all four `db_version_key` options are stamped.
3. **Given** the `vendor/autoload_packages.php` file is missing entirely (e.g., site pulled from git without `composer install`), **When** the site admin activates the plugin, **Then** the pre-existing priority-1 pre-guard on `activate_<plugin>` still fires `wp_die` with a clear message and the plugin does NOT partially initialise.

---

### User Story 2 - Self-Healing Activation Safety Belt (Priority: P2)

A future activation attempt succeeds partway — stamping a `db_version_key` option — but the physical table creation silently fails (e.g., a transient MySQL permissions issue, disk full, or a mid-flight abort). On the next activation attempt, the physical table is absent even though the version option is present. Without a self-heal, BerlinDB's `maybe_upgrade()` would short-circuit because the stamped version equals the target and never re-run the `dbDelta` CREATE. The phantom-version guard on every Table subclass detects the missing physical table, drops the stale version option, and lets `parent::maybe_upgrade()` recreate the table.

**Why this priority**: No phantom-version option exists on the fresh installs Feature 011 ships to today, so this story cannot be reproduced without deliberately manufacturing the state. It is a durable safety belt for the family of BerlinDB-backed tables this plugin will ship over time — cheap, matches the sibling plugin's canonical shape, and prevents a class of hard-to-diagnose "table doesn't exist" bugs from re-emerging in later features. Independent of the DB rename, the pattern belongs on every Table subclass.

**Independent Test**: On any install (test site), deactivate the plugin, delete a random one of the four `wp_acrossai_mcp_*` tables via SQL while leaving its `db_version_key` option in place, then reactivate the plugin. Verify the deleted table exists again after activation and no diagnostic signal is emitted (silent self-heal per Clarification Q1).

**Acceptance Scenarios**:

1. **Given** a `db_version_key` option is stamped and the corresponding physical table is missing, **When** activation runs, **Then** the guard drops the option and `parent::maybe_upgrade()` recreates the table.
2. **Given** a `db_version_key` option is stamped and the corresponding physical table exists with valid data, **When** activation runs, **Then** the guard is a no-op — no ALTER, no re-seed, no option touch.

---

### User Story 3 - Caller Sweep Lands in the Same Pull Request (Priority: P1)

Every existing consumer of the four Query classes and four Row classes — the OAuth handlers under `includes/OAuth/**`, `includes/REST/CliController.php`, `includes/MCP/Controller.php`, `includes/Database/CliAuthLog/Recorder.php`, and `admin/Partials/CliAuthLogListTable.php` — is edited in the same feature branch to consume the new BerlinDB-derived Query/Row surface. After the sweep, PHPStan level 8 and PHPCS remain clean across the whole plugin; no stale reference to a pre-migration class name, method signature, or Row public property remains. `admin/Partials/CliAuthLogListTable.php` keeps its `WP_List_Table` shape (constitution §IV DEV1 exception); the sweep does not convert it to DataViews.

**Why this priority**: Backward compatibility is dropped (per Clarification Q4), so the migration is only complete when every callsite that touches the four modules compiles and behaves against the new BerlinDB-derived surface. Leaving stale references in even one caller would fatal at first hit. This story is P1 because it defines the boundary of the feature — without a passing sweep, the four Query rewrites cannot ship.

**Independent Test**: After the migration, run `vendor/bin/phpstan analyse --level=8` and `vendor/bin/phpcs` across the whole plugin and confirm zero errors. Run the OAuth code-redemption flow, the OAuth token-issue flow, the audit-log write path, and the CLI auth log admin list-table render — each MUST succeed with unchanged operator-visible behaviour (server list appears, auth codes redeem exactly once, audit rows write, list table renders).

**Acceptance Scenarios**:

1. **Given** the caller sweep has landed, **When** PHPStan level 8 runs against the whole plugin, **Then** it returns zero errors — every callsite that referenced a pre-migration class name, method signature, or Row property has been updated.
2. **Given** the caller sweep has landed, **When** PHPCS runs against the whole plugin, **Then** it returns zero errors and zero warnings.
3. **Given** the caller sweep has landed, **When** the CLI auth log admin list table is loaded by a `manage_options` user, **Then** it renders using its existing `WP_List_Table` shape — the sweep did not convert it to DataViews.
4. **Given** the caller sweep has landed, **When** the SEC-001 atomic redeem path fires (one-shot CLI auth code redemption under concurrent hit), **Then** exactly one caller receives a truthy result, matching the pre-migration semantic — regardless of whether the method has been renamed.

---

### Edge Cases

- **Version option is set to a future / unknown value**: If a `db_version_key` option is set to a value higher than the Table subclass's `$version`, BerlinDB's `maybe_upgrade()` treats "already up to date" as no-op — no ALTER, no data touch. Preserved.
- **Physical table exists but a column is missing** (partial-DDL install): BerlinDB's diff engine detects the drift and fires the compensating `ALTER` — native BerlinDB behaviour, not suppressed.
- **`vendor/autoload_packages.php` is missing on activation**: The priority-1 pre-guard on `activate_<plugin>` already fires `wp_die` with an operator-visible error; preserved verbatim.
- **Two of the four Table subclasses upgrade successfully, a third fails mid-flight**: The exception propagates per FR-016 and activation fatals — operator sees a stack trace, the successful two remain stamped, the third is unstamped, and the phantom-version guard self-heals the third on the next activation attempt after the underlying fault is fixed. The fourth module is never attempted in that activation.
- **A row's public property receives a `null` value from a nullable column** (`revoked_at`, `approved_at`, `completed_at`, `details_json`, or their renamed equivalents): Row public property MUST accept `null`; downstream callers already handle this.
- **OAuthToken `active_only` filter with an empty result set**: The subclass override returns `array()` (empty), not `null` — canonical PHP array-return contract.
- **DEV1 boundary drift**: A sweep edit in `admin/Partials/CliAuthLogListTable.php` that adds a DataViews import or replaces the `WP_List_Table` extend clause is out of scope — must be rejected in code review, per FR-021.

---

## Requirements *(mandatory)*

### Functional Requirements

**Data & Schema (Clean Install Shape)**

- **FR-001**: Each of the four Table subclasses MUST declare a `$name`, `$version`, `$db_version_key`, `$schema`, and `$global = false` matching the sibling plugin's conventions. Concrete values (table name prefix stem, option key spelling, `$version` starting value) are a plan-phase decision; the constraint is that they follow the sibling plugin's naming shape verbatim so the two AcrossAI plugins remain uniform.
- **FR-002**: Each Schema subclass's `$columns` array MUST fully describe the module's data model (auth-log row, token row, audit row, MCP-server row) so `dbDelta` can create the table from `$columns` alone — no external `CREATE TABLE` SQL is needed.
- **FR-003**: Each Schema subclass's `$indexes` array MUST declare a `primary` index plus any additional `key` or `unique` indexes required by the module's query patterns (e.g. `unique` on hashed one-shot codes, compound `key` on `(server_id, created_at)` for time-scoped lookups, `unique` on `access_token_hash`).
- **FR-004**: No data-migration step is required — Feature 011 ships to zero live installs. Fresh activation on any target is expected to create tables from scratch.

**Public API Surface (Sibling-Plugin Convention)**

- **FR-005**: The four Query subclasses MUST expose singleton `instance()` and MUST inherit the BerlinDB base-class public method surface (`query()`, `add_item()`, `update_item()`, `delete_item()`) with the base signatures unchanged. Any custom method name added on top of the base surface follows the sibling plugin's convention (snake_case, verb-first, module-scoped).
- **FR-006**: The bespoke atomic-CAS redeem method for one-shot CLI auth codes (currently `redeem_atomic( int $id, string $now ): bool`) MUST preserve its **semantic contract** exactly, even if the method or column names change:
  - SQL predicate MUST remain `WHERE id = %d AND <completed_at_column> IS NULL` — checked against a physical column, not just PHP state.
  - Return value MUST remain a boolean equivalent to `1 === (int) $wpdb->rows_affected` — never a Row object, never `null`.
  - The method MUST use `$wpdb->query( $wpdb->prepare( ... ) )` with `%d` for the identifier and `%s` for the timestamp; identifier interpolation MUST use `%i` where an identifier is referenced dynamically.
  This is SEC-001 (constitution §III + BUGS.md B10). The atomic-CAS is the only defense against concurrent duplicate redemption; renaming the method is allowed only if this contract is preserved bit-for-bit.
- **FR-007**: The bespoke retention-purge methods (currently `delete_expired_oauth_codes( string $cutoff ): int` on the CLI auth-log module and `delete_older_than( string $datetime ): int` on the OAuth audit module) MUST remain single prepared `DELETE` statements returning an `int` row-count. Method names may change, callers get the sweep.
- **FR-008**: The OAuthToken Query subclass MUST support the "active tokens only" concept (rows where the revoke-at column IS NULL AND the expiry column > `current_time( 'mysql', 1 )`). Implementation MUST be a post-query PHP `array_filter()` on the Row set returned by `parent::query()` — the filter key is consumed and `unset()` before delegating to the parent (per Clarification Q3). A BerlinDB `Where`-operator push-down is out of scope for Feature 011.
- **FR-009**: Row subclasses MUST extend `\BerlinDB\Database\Kern\Row` and expose a public property per column in the corresponding Schema. Property names follow the column names verbatim. A `to_array()` helper MUST be present on each Row subclass returning an associative array of `column_name => value` — external consumers (list tables, admin views, REST controllers, audit recorders) all use this helper.
- **FR-010**: Column semantics that carry security meaning MUST be preserved even under rename:
  - Any column that stores a hashed one-shot credential or hashed access token MUST remain a `char(64)` (SHA-256 hex length) — a length-narrowing rename would leak plaintext bits. Applies to the CLI auth-code hash column and the OAuth access-token hash column.
  - Any column storing PKCE code-challenge state MUST retain its exact length (43 chars for the S256 challenge).
  This is S3 (constitution §III).

**Activation Lifecycle**

- **FR-011**: `acrossai_mcp_manager_activate()` (the priority-10 callback registered on `register_activation_hook`) MUST `require_once __DIR__ . '/vendor/autoload_packages.php';` BEFORE requiring `includes/Activator.php`, so the BerlinDB Kern base classes and the four Query FQNs autoload cleanly during activation.
- **FR-012**: The priority-1 pre-guard on `activate_<plugin>` MUST remain a fail-early file-existence check with `wp_die` on missing vendor — it MUST NOT be moved to global scope, and its `wp_die` branch on `! file_exists( ... )` MUST be preserved (DEV4 / D15 / B14).
- **FR-013**: `Activator::activate()` MUST invoke `MCPServerTable::instance()->maybe_upgrade()`, `CliAuthLogTable::instance()->maybe_upgrade()`, `OAuthTokenTable::instance()->maybe_upgrade()`, and `OAuthAuditTable::instance()->maybe_upgrade()` — exactly one call per module — in place of the four current `class_exists( XxxQuery::class )` + `XxxQuery::maybe_create_table()` blocks.
- **FR-014**: The static wrapper method `Query::maybe_create_table()` on each of the four Query classes MUST be deleted; no file in the plugin (including callers) may reference `maybe_create_table()` after Feature 011. Grep-verified by `grep -rEn '\bmaybe_create_table\b' --include='*.php' includes/ admin/ public/ acrossai-mcp-manager.php` returning zero matches.
- **FR-015**: `Activator::activate()` MUST NOT wrap the four `Table::instance()->maybe_upgrade()` calls in a defensive `class_exists( XxxTable::class )` guard (D4 rationale — a defensive guard would mask a real regression) and MUST NOT wrap them in `try/catch` (per Clarification Q2 — activation fatals loudly if any of the four throws). Exceptions propagate to WordPress's standard recovery path.
- **FR-016**: MCPServer's current inline `insert_default_server()` logic MUST be extracted into a new class `DefaultServerSeeder` at `includes/Database/MCPServer/DefaultServerSeeder.php`, exposing a static `seed()` method. `DefaultServerSeeder` is a stateless pure service class (A11/A15 family) — no singleton, no hook wiring, private-static SLUG constant. The default row's field values follow the sibling plugin's default seed shape; where the sibling plugin does not have an equivalent, use the current plugin's values (`server_name` = 'Default MCP Server', `server_slug` = the module's slug constant, `is_enabled` = 0, `registered_from` = 'plugin', `server_route_namespace` = 'mcp', `server_route` = the slug, `server_version` = 'v1.0.0', empty claude-connector fields). The method MUST be idempotent: SELECT-COUNT-first, early-return if non-zero. On successful insert, `wp_cache_delete( 'all_servers', 'acrossai_mcp' )` MUST fire at the end.
- **FR-017**: `Activator::activate()` MUST call `DefaultServerSeeder::seed()` immediately after `MCPServerTable::instance()->maybe_upgrade()` and BEFORE the other three modules' `maybe_upgrade()` calls (order: MCPServer table → seed → CliAuthLog table → OAuthToken table → OAuthAudit table).

**Request-Time Table Boot (BerlinDB DB Interface Registration)**

- **FR-028**: `Main::load_hooks()` MUST instantiate all four Table subclasses via `Table::instance()` BEFORE `define_admin_hooks()` and `define_public_hooks()` run. The four calls live in a private helper `Main::bootstrap_database_tables()` invoked exactly once per request, unconditionally under the `apply_filters( 'acrossai_mcp_manager_load', true )` gate. Rationale: BerlinDB v3's `Query` looks up the physical table name (`$wpdb->prefix . $name`) from a global DB interface populated by the Table subclass's `sunrise()` boot. If no Table subclass is instantiated during the request lifecycle, the Query base class falls back to the `$table_alias` as the FROM clause and produces `Table 'db.<alias>' doesn't exist` fatals at the first Query execution — observed in the field on 2026-07-02 (admin list-table render + REST `has_any_enabled_server` at `rest_api_init`). Activation-time `Table::instance()` calls (FR-013) satisfy DDL lifecycle but do NOT persist into subsequent request cycles. Neither this call nor the FR-013 activation calls may be wrapped in `class_exists` guards — after FR-011 and the vendor autoloader's `plugins_loaded` P0 registration, the four Table FQNs are guaranteed to resolve; a defensive guard would mask a real regression.

**Phantom-Version Guard (Safety Belt)**

- **FR-018**: Every one of the four Table subclasses MUST override `maybe_upgrade()` with the phantom-version guard: `if ( ! $this->exists() ) { delete_option( $this->db_version_key ); } parent::maybe_upgrade();` — verbatim, no early return, no exception. The guard MUST fire silently on the self-heal path — no `error_log()`, no admin notice, no transient — matching the sibling plugin's canonical implementation (per Clarification Q1).
- **FR-019**: The guard MUST be a subclass method with the same signature and visibility (`public function maybe_upgrade(): void`) as the sibling plugin's canonical implementation at `AcrossAI_Abilities_Table.php:96-101`.

**Caller Sweep**

- **FR-020**: Every callsite of the four Query classes and four Row classes MUST be updated in the same feature branch to consume the new BerlinDB-derived surface. The callsite files, enumerated by pre-flight grep, are: everything under `includes/OAuth/`, `includes/REST/CliController.php`, `includes/MCP/Controller.php`, `includes/Database/CliAuthLog/Recorder.php`, `admin/Partials/CliAuthLogListTable.php`, `admin/Partials/Settings.php`, `admin/Partials/MCPServerListTable.php`, `admin/Partials/ApplicationPasswords.php`, and any other file the grep surfaces. Two grep passes are required — the qualified-name form AND the short-name-via-`use` form — because the latter is trivial to miss with a single regex (Feature 011 caught 11 short-name survivors post-workflow):
  ```
  # Pass 1 — qualified 'new XxxQuery()' + all use imports of Query/Row FQNs.
  grep -rEn '(new [A-Za-z_]*(MCPServer|CliAuthLog|OAuthToken|OAuthAudit)[A-Za-z_]*Query|use .*(MCPServer|CliAuthLog|OAuthToken|OAuthAudit)\\(Query|Row))' \
      --include='*.php' includes/ admin/ public/ acrossai-mcp-manager.php
  # Pass 2 — short-name 'new Query()' bound via 'use ...\Query;' (no `as` alias).
  grep -rEn '\bnew\s+Query\s*\(\s*\)' \
      --include='*.php' includes/ admin/ public/ acrossai-mcp-manager.php
  ```
- **FR-021**: The sweep in `admin/Partials/CliAuthLogListTable.php` MUST be limited to renames, method-signature updates, and Row property renames. It MUST NOT convert the class away from `WP_List_Table` — DEV1 is a scoped, ratified exception and this feature is not authorized to widen it. Any DataViews import added to this file is a review-time rejection.
- **FR-022**: `MCPServer\Table::DEFAULT_SERVER_SLUG` MUST be relocated to `DefaultServerSeeder::SLUG`; every callsite that referenced the old constant is updated in the sweep. (Unconditional — no grep gate.)

**Memory Hygiene**

- **FR-023**: Decision entries in `docs/memory/DECISIONS.md` that Feature 011 supersedes MUST be marked as **Superseded (Feature 011)** while retaining their body verbatim (per `PATTERN-MEMORY-SUPERSESSION-VS-ANNOTATION`). At minimum, this list includes **D9** ("BerlinDB-style Query interface hand-rolled — no berlindb/core vendor dep") and **D7** ("Activator does NOT call insert_default_server()"). It also includes any entry matching prefixes `DEC-DBDELTA-*`, `DEC-CUSTOM-TABLE-LIFECYCLE`, or `DEC-DB-VERSION-OPTION-GUARD` if present in the memory. **D4** ("class_exists guards silent no-op") is scope-narrowed by this feature (still active for non-DB `class_exists` patterns) — annotate with a forward-pointer note per PATTERN, do not mark as superseded. The `sanitize_key()` and `%i`-placeholder decision entries are NOT touched.
- **FR-024**: `docs/memory/WORKLOG.md` MUST gain a Feature 011 milestone entry capturing (a) why the phantom-version guard is durable, (b) the future mistake it prevents, (c) evidence (the observed activation-log symptom this feature originally targeted), (d) file paths of the four Table subclasses.
- **FR-025**: `docs/memory/INDEX.md` MUST reflect the DECISIONS.md supersession changes and MUST append a WORKLOG index row for Feature 011.
- **FR-026**: `README.txt` MUST gain an Unreleased changelog bullet describing the BerlinDB adoption and calling out that the feature ships to zero live installs (no data migration required, tables and options are freshly created on activation).
- **FR-027**: `docs/planings-tasks/README.md` MUST list `011-berlindb-migration.md` alongside the existing planning docs.

### WordPress Requirements

**PHP Version**: PHP 8.1+ (plugin bumped to 8.1 in Feature 010 for composer alignment; BerlinDB Core 3.0 targets 8.1+ as well).
**WordPress Version**: 6.9+ (unchanged from prior features).
**Multisite**: Single-site only. All four Table subclasses declare `protected $global = false;`.
**Required Plugins / Packages**: `berlindb/core: ^3.0.0` (already installed via Feature 010, no version change in this feature). No new WordPress plugin dependencies.
**Optional Integrations**: None introduced by this feature.

### Module Placement

**PHP Class(es)** (all under namespace `AcrossAI_MCP_Manager\Includes\Database\...`):

- `includes/Database/MCPServer/Table.php` → extends `\BerlinDB\Database\Kern\Table` (full rewrite).
- `includes/Database/MCPServer/Schema.php` → extends `\BerlinDB\Database\Kern\Schema` (full rewrite).
- `includes/Database/MCPServer/Query.php` → extends `\BerlinDB\Database\Kern\Query` (full rewrite).
- `includes/Database/MCPServer/Row.php` → extends `\BerlinDB\Database\Kern\Row` (full rewrite).
- `includes/Database/MCPServer/DefaultServerSeeder.php` → **NEW** stateless pure service class (A11/A15 family) carrying the extracted seed logic and the relocated `SLUG` constant.
- Same four Table / Schema / Query / Row subclasses for each of CliAuthLog, OAuthToken, OAuthAudit (twelve file rewrites total across the three remaining modules).
- `includes/Activator.php` → delta edits (four `use ... Table as XxxTable;` imports, one `use ... DefaultServerSeeder;` import, four `Table::instance()->maybe_upgrade()` calls, one `DefaultServerSeeder::seed()` call, removal of the four `use ... Query as XxxQuery;` imports plus their `class_exists`+`maybe_create_table` blocks).
- `acrossai-mcp-manager.php` → one new line inside `acrossai_mcp_manager_activate()` requiring `vendor/autoload_packages.php`.
- Caller-sweep files (edits only, no new files): everything under `includes/OAuth/`, `includes/REST/CliController.php`, `includes/MCP/Controller.php`, `includes/Database/CliAuthLog/Recorder.php`, `admin/Partials/CliAuthLogListTable.php`.

**Hook Registration**: This feature does not add or remove any `add_action`/`add_filter` calls. `Activator::activate()` continues to be wired via `register_activation_hook` from the plugin bootstrap; no `Main::define_*_hooks()` changes. Caller-sweep edits MUST NOT introduce a new hook on any callsite (A1).

### Admin UI Requirements

This feature has no new admin UI surface. `admin/Partials/CliAuthLogListTable.php` retains its `WP_List_Table` shape per DEV1 exception — sweep edits are limited to renames and method-signature updates.

### REST API Contract

This feature has no new REST route surface. Existing REST routes under `includes/REST/CliController.php` are edited in the sweep to consume the renamed Query/Row API but their HTTP-facing contract (route paths, methods, request/response schemas) MUST remain unchanged — that contract is governed by Feature 006 and is not in scope for Feature 011.

### Database / Storage

**Custom DB tables** (four, freshly created on activation):

- MCPServer module — table + `db_version_key` option follow sibling-plugin convention (plan-phase decision).
- CliAuthLog module — table + `db_version_key` option follow sibling-plugin convention.
- OAuthToken module — table + `db_version_key` option follow sibling-plugin convention.
- OAuthAudit module — table + `db_version_key` option follow sibling-plugin convention.

Justification: The four data models are inherently relational (multi-column rows queried by compound indexes) and cannot fit `options`/`meta` APIs. Custom tables are justified per constitution Architecture §Database.

Activation hook: `register_activation_hook` continues to be the single call site; the four `Table::instance()->maybe_upgrade()` calls drive `dbDelta` under the hood via BerlinDB.

### Security Checklist

*(Verifies the migration does not regress the plugin's security posture.)*

- [ ] `redeem_atomic` (or its renamed equivalent) preserves the SEC-001 atomic-CAS predicate — same physical-column-guarded `WHERE`, same `rows_affected` check (FR-006)
- [ ] All Query methods continue to use `$wpdb->prepare()` — no raw interpolated queries introduced by the sweep (S4)
- [ ] The `%i` placeholder for identifier interpolation continues to be used where the current implementation uses it
- [ ] Hashed columns (SHA-256 access token, SHA-256 auth code) remain `char(64)` (FR-010, S3)
- [ ] No new `permission_callback`, capability check, or nonce is added — this feature has no user-facing surface
- [ ] The activation-time `require_once vendor/autoload_packages.php` (FR-011) reads a file under the plugin's own directory (`__DIR__`) — no user input, no path traversal risk
- [ ] `DefaultServerSeeder::seed()` retains the pre-migration guard against duplicate seeding (SELECT COUNT first; return early if non-zero)
- [ ] Caller-sweep edits do NOT bypass B7 (mass-assignment): callers writing user-input to `add_item`/`update_item` MUST filter against the Schema column list before persisting

### Key Entities

- **MCP Server**: Represents an MCP server registration exposed by the plugin. Attributes: name, slug, description, enabled flag, registration source, route namespace, route, version, Claude connector client id/secret/redirect URI, created-at timestamp.
- **CLI Auth Log**: Represents one attempt through the CLI OAuth 2.1 authorisation-code flow. Attributes: server id, server slug, user id, status, failure code, hashed auth code, application-password UUID, redirect URI, PKCE code challenge + method, scope, approved-at, completed-at, created-at.
- **OAuth Token**: Represents an issued OAuth access token. Attributes: hashed access token, server id, user id, source auth-log id, scope, created-at, expires-at, revoked-at.
- **OAuth Audit Event**: Represents a single OAuth-relevant lifecycle event for compliance. Attributes: event type, server id, user id, client id, short hashed token prefix, endpoint, structured detail payload, created-at.

---

## Success Criteria *(mandatory)*

### Definition of Done Gates

All of the following MUST pass before this feature is considered complete:

- [ ] PHPCS validation: zero errors and zero warnings (`vendor/bin/phpcs`)
- [ ] PHPStan level 8: zero errors (`vendor/bin/phpstan`) — whole plugin, including swept callers
- [ ] PHPUnit tests written and passing for the phantom-version guard on each of the four Table subclasses, and for the OAuthToken `active_only` filter override, and for the atomic-CAS redeem method's rows-affected contract
- [ ] Security checklist above: all applicable items verified
- [ ] Every task in the tasks.md gate list leaves PHPStan level 8 + PHPCS individually green before the next task begins (Constitution §VII per-task gating)
- [ ] `grep -rEn '\bmaybe_create_table\b' --include='*.php' includes/ admin/ public/ acrossai-mcp-manager.php` returns zero matches
- [ ] `admin/Partials/CliAuthLogListTable.php` still extends `WP_List_Table` after the sweep (DEV1 boundary check)
- [ ] `docs/memory/INDEX.md`, `docs/memory/DECISIONS.md`, `docs/memory/WORKLOG.md`, `README.txt`, and `docs/planings-tasks/README.md` are internally consistent after the memory-hygiene pass (INDEX rows match DECISIONS statuses; supersession list includes D9 + D7)

### Measurable Outcomes

- **SC-001**: On a fresh WordPress install with the plugin present and no options / no tables, one activation succeeds without fatal, stamps all four `db_version_key` options, creates all four tables with BerlinDB-derived schemas, and seeds the default MCP server row. Verifiable via `wp option get` and `wp db query "SHOW TABLES LIKE 'wp_acrossai_mcp_%'"`.
- **SC-002**: PHPStan level 8 and PHPCS return zero errors across the whole plugin after the caller sweep lands — including every swept file (`includes/OAuth/**`, `includes/REST/CliController.php`, `includes/MCP/Controller.php`, `includes/Database/CliAuthLog/Recorder.php`, `admin/Partials/CliAuthLogListTable.php`).
- **SC-003**: Zero references to `maybe_create_table` remain in the plugin's PHP surface after Feature 011 (grep-verified). Zero references to the four Query classes' `maybe_create_table()` static wrappers remain in `Activator.php`.
- **SC-004**: On a test install where a `db_version_key` option is stamped and its corresponding physical table has been dropped manually, one plugin (re)activation recreates the table silently — no log line, no admin notice, no operator intervention required. Verifiable in under 3 minutes on a local site.
- **SC-005**: The SEC-001 atomic-CAS redeem semantic contract is preserved: under concurrent hits on the same one-shot auth code, exactly one caller receives a truthy result and all others receive falsy — verified by a PHPUnit test that seeds one auth-code row and invokes the redeem method twice.
- **SC-006**: The Feature 011 branch reaches merge with per-task PHPStan level 8 + PHPCS gating passing on every task; the memory hygiene changes leave `docs/memory/INDEX.md`, `docs/memory/DECISIONS.md`, `docs/memory/WORKLOG.md`, `README.txt`, and `docs/planings-tasks/README.md` internally consistent (INDEX rows match DECISIONS statuses; D9 + D7 explicitly marked Superseded (Feature 011)).

---

## Assumptions

- The composer package `berlindb/core: ^3.0.0` is already installed at `vendor/berlindb/core/src/Database/Kern/` (Feature 010 established this dependency and its autoloader wiring under `vendor/autoload_packages.php`).
- The sibling plugin `acrossai-abilities-manager` at `../acrossai-abilities-manager/includes/Modules/Abilities/Database/AcrossAI_Abilities_{Table,Schema,Query,Row}.php` is the canonical reference for the BerlinDB subclass pattern, including the phantom-version guard at `AcrossAI_Abilities_Table.php:96-101`. Where this specification does not explicitly cover a design choice (e.g., column naming shape, method-name conventions, cache-group strings, default seed values), the sibling plugin's shape is the default.
- No live installs of the plugin exist. Feature 011 ships to dev/local only, so table renames, option-key renames, column restructures, and API breakage are all free actions.
- The plugin's parent-menu shared-vendor-package bootstrap (Feature 010 / DEV4) and the priority-1 pre-activation vendor guard (Feature 010 / FR-030) are unchanged by Feature 011; FR-011 extends the same defense-in-depth family with an in-callback autoload require.
- Multisite support is out of scope for this feature. `$global = false` is declared on every Table subclass.
- No new WP-CLI command is introduced by this feature.
- The four hashed-column semantics (SHA-256 = 64-char hex) and the PKCE-challenge column length (43 chars for S256) are load-bearing security invariants — even a rename may not narrow those column widths.
