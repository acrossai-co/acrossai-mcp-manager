# Planning: OAuth Per-Server Scoping — First-Class `server_id` on Clients / Tokens / Auth Codes (Feature 032)

> **Reconciled 2026-07-21** with `/speckit-clarify` outcomes for spec 032 (see `specs/032-oauth-per-server-scoping/spec.md` §Clarifications). Four decisions pinned:
> 1. **Observability**: every 403 `acrossai_mcp_oauth_cross_server` fires `do_action( 'acrossai_mcp_oauth_cross_server_attempted', ... )`.
> 2. **Rollout**: ship unconditionally — no feature-flag opt-out. The former "Fallback" clause in Pre-flight Attestation is REMOVED.
> 3. **Legacy DCR rows**: **auto-purged** during the upgrade callback (this REVERSES the original "do not delete" CONSTRAINT). Live AI-host sessions bound to legacy rows disconnect on next request; README + release-note MUST warn operators.
> 4. **Column nullability**: `server_id` ends the upgrade as `NOT NULL` on all three tables. Added as NULL transiently, then MODIFYed to NOT NULL after backfill + purge.

Add first-class `server_id BIGINT UNSIGNED NOT NULL` columns (final state) to the three OAuth entity tables — `wp_acrossai_mcp_oauth_clients`, `wp_acrossai_mcp_oauth_tokens`, `wp_acrossai_mcp_oauth_auth_codes` — via BerlinDB Core 3.0's `$upgrades` callback mechanism (D28 3-part contract), backfill existing rows, purge remaining legacy `server_id IS NULL` rows, MODIFY the column to NOT NULL, migrate the client-uniqueness constraint from `UNIQUE(client_id)` to composite `UNIQUE(client_id, server_id)`, propagate `server_id` end-to-end through the OAuth authorize→token→refresh flow, and require + validate `server_id` on every mutating REST endpoint in the admin surface (`ConnectorAdminController`) and DCR endpoint (`ClientRegistrationController`).

This feature closes a **critical cross-server privilege-escalation gap** surfaced during audit: an admin on Server A's Connectors tab (`?tab=ai-connectors`) can currently revoke tokens or delete clients on Server B by modifying the `client_id` in the outbound REST body — nothing at the SQL layer or REST layer prevents cross-server mutation. The `client_id` prefix convention (`server-{id}-{slug}-{rand}`) that today encodes server ownership is (a) not enforced by the schema, (b) not validated by the REST handlers, and (c) does not exist at all for DCR-registered clients (Claude.ai, ChatGPT, etc.). Same-shape leak exists on the READ side: `TokensQuery::get_active_user_ids_by_client_id()` returns users across all servers holding tokens for the same `client_id`, which the AI Connectors tab uses to list "authorized users" per connector — leaking Server B's user list into Server A's admin surface.

The migration preserves **table names and `db_version_key` option names byte-for-byte** (backwards-compat contract with any prior install's data). The three new columns are added as `NULL` transiently within the upgrade callback so existing rows do not need `server_id` before the backfill runs; the callback then backfills, purges any row that still has `server_id IS NULL` after backfill (legacy pre-F032 DCR clients + their tokens + their auth codes), and finally MODIFYs the column to `NOT NULL`. Every step is idempotent (guarded by `INFORMATION_SCHEMA.COLUMNS` / `INFORMATION_SCHEMA.STATISTICS` checks) so a mid-migration crash re-runs safely. Admin-generated clients whose `client_id` matches `/^server-(\d+)-/` are backfilled from the prefix. Legacy DCR-registered clients (no server-id prefix) are **deleted** — the OAuthClients callback fires `do_action( 'acrossai_mcp_oauth_legacy_dcr_purged', $clients_deleted, $tokens_deleted, $auth_codes_deleted )` exactly once at the end for operator observability. Live AI-host sessions bound to legacy rows disconnect on the next request; users re-run the OAuth authorize flow to reconnect. **README.txt and the plugin's user-facing update-notice screen MUST warn operators of this consequence prominently before upgrade.**

The one deliberate behavioural change beyond the security fix is that DCR clients become **per-server**: two servers can now each register the same connector (e.g., Claude Desktop on Server A + Claude Desktop on Server B) as **two independent rows** under the new composite `UNIQUE(client_id, server_id)` constraint. Prior behaviour forced global uniqueness on `client_id`; the second server's DCR request would either fail or reuse the first server's row, both of which are wrong. The DCR endpoint captures `server_id` by resolving the RFC 8707 `resource` parameter against `MCPServerQuery` at registration time.

The pattern this feature applies is the F029 `D28` schema-drift-reconciliation contract, extended from single-table (F029 `CliAuthLog` + `MCPServer`) to three coordinated tables. Every `Schema.php` change ships with a bumped `Table::$version` + registered `$upgrades` callback + idempotent `INFORMATION_SCHEMA` check + backfill statement. `Main::reconcile_database_schemas()` on `admin_init@3` (already wired in F029) fires the callbacks on the next admin request after deploy — no operator action required.

---

## Speckit Workflow

```markdown
# 1. Branch
/speckit.git.feature "oauth-per-server-scoping"

# 2. Specify
/speckit.specify "Add first-class server_id scoping to the three OAuth
entity tables (wp_acrossai_mcp_oauth_clients, wp_acrossai_mcp_oauth_tokens,
wp_acrossai_mcp_oauth_auth_codes). Each table gains a server_id BIGINT
UNSIGNED NOT NULL (final state) column via BerlinDB $upgrades callback
per D28: bump Table $version, register $upgrades entry with matching
upgrade_to_<v> method that (a) checks INFORMATION_SCHEMA.COLUMNS for
column presence + adds NULL-allowed column if missing, (b) runs the
backfill UPDATE (admin clients from server-{id}- prefix parse; tokens +
auth_codes via JOIN on client_id → clients.server_id), (c) purges any
row where server_id IS NULL after backfill (legacy pre-F032 DCR + descendants),
(d) OAuthClients ONLY: swaps UNIQUE(client_id) → composite
UNIQUE(client_id, server_id) — ADD composite BEFORE DROP standalone to
avoid mid-migration constraint gap, (e) MODIFY column to NOT NULL
guarded by INFORMATION_SCHEMA.COLUMNS.IS_NULLABLE = 'YES' check for
idempotency, (f) OAuthClients ONLY as the last step: fire
do_action('acrossai_mcp_oauth_legacy_dcr_purged', $clients_deleted,
$tokens_deleted, $auth_codes_deleted) if any purge counted > 0. Update every Query class to accept + filter
by server_id: ClientsQuery::query, ClientsQuery::find_by_client_id (add
required server_id param), ClientsQuery::find_admin_clients_for_server_connector
(switch from prefix-LIKE filter to server_id column filter),
ClientsQuery::find_dcr_clients (grow optional server_id filter),
TokensQuery::revoke_by_client_id (add required server_id param),
TokensQuery::query, TokensQuery::get_active_user_ids_by_client_id (rename
to _and_server_id, add required server_id param — closes user-list
cross-server leak), AuthCodesQuery same. TokensQuery::revoke_by_user_id
STAYS server-neutral (user deletion is site-wide per FR-042 — regression
protection required). Propagate server_id through the OAuth flow:
AuthorizationController resolves server_id from RFC 8707 resource param
at authorize time + persists on auth_code; TokenController copies
server_id from auth_code onto token at code-exchange time + from prior
token onto new token at refresh; TokenController verifies client.server_id
matches auth_code.server_id (defense-in-depth). ClientRegistrationController:
handle_register (DCR) resolves server_id from RFC 8707 resource param in
DCR body + rejects with invalid_target on unresolvable resource;
handle_admin_generate already knows server_id + persists.
ConnectorAdminController: handle_revoke_client_tokens + handle_delete_client
require server_id param + verify the referenced client's server_id matches
the request (return WP_Error 'acrossai_mcp_oauth_cross_server' 403 on
mismatch — 403 not 404 so existence isn't leaked cross-server; every 403
MUST fire do_action('acrossai_mcp_oauth_cross_server_attempted', $client_id,
$server_id_requested, $server_id_actual, get_current_user_id(), time())
immediately BEFORE the WP_Error return — D19 fail-open observability
precedent matching F015/F020/F029/F030);
handle_revoke_connector_tokens (nuclear) extends DCR filter path to also
filter by server_id (previously matched by profile only → cross-server
leak). AIConnectorsTab::render_connections_panel emits
data-acrossai-server-id alongside data-acrossai-client-id on every
Revoke/Delete button + switches its 'authorized users' listing call from
get_active_user_ids_by_client_id to the new server-scoped helper.
F024 nested-tabs JS bundle includes server_id in every mutating REST body
+ surfaces a specific error message on the new 403 code. Eight PHPUnit
tests in tests/phpunit/OAuth/PerServerIsolationTest.php cover: (1) A
revoke leaves B's tokens intact, (2) A delete leaves B's client row
intact, (3) same DCR connector registers on two servers as two distinct
rows, (4) REST 403 on server_id mismatch, (5) authorized-users listing
filters by server_id, (6) regression: UserLifecycle::on_user_deleted
still cascades across ALL servers, (7) every 403 acrossai_mcp_oauth_cross_server
response fires acrossai_mcp_oauth_cross_server_attempted action with correct
args (spy listener), (8) legacy DCR purge on upgrade fires
acrossai_mcp_oauth_legacy_dcr_purged action with correct counts. Three
schema-upgrade regression tests (one per OAuth table) mirror F029's
CliAuthLog + MCPServer upgrade test shape AND additionally verify
IS_NULLABLE = 'NO' post-migration AND that INSERT without server_id
fails with a MySQL constraint violation. Auto-purge legacy DCR rows
with NULL server_id during the upgrade callback (this REVERSES the
original 'do not delete' constraint per 2026-07-21 clarify Q3, A-aggressive
form — live AI-host sessions disconnect; README + release-note warn
operators). Ship unconditionally — no feature-flag opt-out (per Q2). Do
not add server_id column to wp_acrossai_mcp_oauth_audit (audit rows
already record server_id per F029). Do not touch UserLifecycle cascade
semantics (site-wide by design). Memory hygiene per
PATTERN-MEMORY-SUPERSESSION-VS-ANNOTATION: no existing DEC/BUG entries
are superseded; two new entries added — DEC-F032-OAUTH-SERVER-ID-FIRST-CLASS
(active) and B-CROSS-SERVER-BYPASS-VIA-CLIENT-ID-ONLY (active,
generalizable grep-gate pattern for any per-tenant admin endpoint that
accepts only a tenant-scoped identifier)."
```

### Detailed Description for `/speckit.specify`

> **Before writing a single line of code, read and internalize all of
> these governing documents in full:**
>
> 1. `AGENTS.md` — this plugin's singleton pattern, hook registration
>    rules, Before Commit Checklist.
> 2. `docs/planings-tasks/011-berlindb-migration.md` — the reference BerlinDB
>    migration template this feature mirrors.
> 3. `docs/memory/DECISIONS.md` §D28 — BerlinDB schema-drift reconciliation
>    (bump `$version` + register `$upgrades` callback + rely on
>    `Main::reconcile_database_schemas()` at `admin_init@3`). This is the
>    canonical column-add contract F032 applies three times.
> 4. `docs/memory/BUGS.md` §B34 — silent write-loss on schema drift
>    (the failure mode a bare `$version` bump without matching `$upgrades`
>    callback would reintroduce).
> 5. `includes/Database/CliAuthLog/Table.php::upgrade_to_1_0_1` (F029) —
>    reference impl for the MODIFY-column pattern.
> 6. `includes/Database/MCPServer/Table.php::upgrade_to_1_1_1` and
>    `upgrade_to_1_1_2` (F029 + F030) — reference impls for the ADD-column
>    pattern with backfill.
> 7. `specs/005-oauth-connectors/data-model.md:69` — the ORIGINAL spec
>    that specified `server_id` on E2 (Access Token) but was never
>    implemented. This feature closes that spec gap.
> 8. `docs/security-reviews/` — every OAuth-related plan/branch/staged
>    review. F032 must not regress the security posture verified there.
>
> Every decision — column-def choice, index-rename ordering, backfill SQL
> shape, REST endpoint validation shape — must be justified against the
> above. If a choice is not explicitly covered, default to the F029
> `$upgrades` callback shape. Do not write code that would fail any
> Definition-of-Done gate: PHPStan level 8, PHPCS, security review, all
> `__()` calls using the correct text domain `'acrossai-mcp-manager'`.
>
> **Public API artifacts to preserve verbatim (grep-gate before + after):**
>
> - `\AcrossAI_MCP_Manager\Includes\Database\OAuthClients\Query`
> - `\AcrossAI_MCP_Manager\Includes\Database\OAuthClients\Row`
> - `\AcrossAI_MCP_Manager\Includes\Database\OAuthTokens\Query`
> - `\AcrossAI_MCP_Manager\Includes\Database\OAuthTokens\Row`
> - `\AcrossAI_MCP_Manager\Includes\Database\OAuthAuthCodes\Query`
> - `\AcrossAI_MCP_Manager\Includes\Database\OAuthAuthCodes\Row`
> - `\AcrossAI_MCP_Manager\Includes\OAuth\ConnectorAdminController` REST route slugs
> - `\AcrossAI_MCP_Manager\Includes\OAuth\ClientRegistrationController` DCR endpoint slug
> - `\AcrossAI_MCP_Manager\Includes\OAuth\TokenController` grant handler contract (params in / response out)
> - `\AcrossAI_MCP_Manager\Includes\OAuth\UserLifecycle::on_user_deleted` — MUST cascade site-wide (do not add server scoping)
>
> Method SIGNATURES change (adding required `int $server_id`) on the
> mutating helpers — that is intentional. Every call site is inside
> `includes/OAuth/` or the F024 JS layer; the enumerated Query FQNs stay
> constant.
>
> Pre-flight grep (records the callers whose behaviour must be re-validated
> after the migration):
>
> ```
> grep -rEn '(revoke_by_client_id|delete_by_id|get_active_user_ids_by_client_id|find_by_client_id|find_admin_clients_for_server_connector|find_dcr_clients)' \
>     --include='*.php' \
>     includes/ admin/ public/
> ```
>
> Every hit that surfaces here MUST be updated to pass the new required
> `server_id` param (or explicitly accept the server-neutral variant where
> applicable — currently only `UserLifecycle::on_user_deleted`).
>
> Preserved table + version map (data-preservation contract):
>
> | Module | Table (with `$wpdb->prefix`) | `db_version_key` option | Current `$version` | New `$version` |
> | --- | --- | --- | --- | --- |
> | OAuthClients | `acrossai_mcp_oauth_clients` | `wpdb_acrossai_mcp_oauth_clients_version` | TBD (read at implement time) | +0.0.1 |
> | OAuthTokens | `acrossai_mcp_oauth_tokens` | `wpdb_acrossai_mcp_oauth_tokens_version` | TBD (read at implement time) | +0.0.1 |
> | OAuthAuthCodes | `acrossai_mcp_oauth_auth_codes` | `wpdb_acrossai_mcp_oauth_auth_codes_version` | TBD (read at implement time) | +0.0.1 |
>
> ---
>
> **TASK-1 — OAuthClients schema + backfill + composite UNIQUE**
>
> Files:
> - `includes/Database/OAuthClients/Schema.php` (delta: append `server_id` column entry + update `$indexes`)
> - `includes/Database/OAuthClients/Table.php` (delta: bump `$version` + register `$upgrades` entry + implement `upgrade_to_<v>()`)
> - `includes/Database/OAuthClients/Row.php` (delta: add `public $server_id = 0;` property + `(int)` cast in constructor + `to_array()` entry)
> - `includes/Database/OAuthClients/Query.php` (delta: `find_admin_clients_for_server_connector` switches from prefix-LIKE filter to `server_id` column filter; `find_dcr_clients` grows optional `$server_id` filter; new `find_by_client_id_and_server_id( string $client_id, int $server_id ): ?Row` helper for REST endpoints; per-request cache mirroring F017 `ExposureResolver::resolve()` shape.)
>
> Read `includes/Database/MCPServer/Table.php:136-171` (F029 `upgrade_to_1_1_1`) + `:172-...` (F030 `upgrade_to_1_1_2`) BEFORE editing — these are the exact idempotent patterns to mirror.
>
> Schema.php delta — append to `$columns`. **Declare the FINAL (post-migration) shape**: `NOT NULL`, no default. The upgrade callback adds the column as `NULL` transiently, backfills + purges, then MODIFYs to `NOT NULL` as its last step — Schema.php declares the end state so BerlinDB's SHOW CREATE TABLE diff matches after the upgrade completes:
> ```php
> array(
>     'name'     => 'server_id',
>     'type'     => 'bigint',
>     'length'   => '20',
>     'unsigned' => true,
>     'allow_null' => false,
> ),
> ```
> and update `$indexes` — REPLACE the existing `'name' => 'client_id', 'type' => 'unique'` entry with the composite:
> ```php
> array(
>     'name'    => 'client_id_server_id',
>     'type'    => 'unique',
>     'columns' => array( 'client_id', 'server_id' ),
> ),
> ```
> (Note: BerlinDB v3 diff-matches indexes by `name` — using `client_id_server_id` as a new name ensures the diff engine treats the old `client_id` index as removed AND the new composite as added. The upgrade callback below handles the ALTER ordering safely.)
>
> Table.php delta — bump `$version = '<current>+0.0.1';`. Add to `$upgrades`:
> ```php
> '<new-version>' => 'upgrade_to_<new-version>',
> ```
> Implement `upgrade_to_<new-version>()` — six ordered steps, each idempotent. This is the ONE callback that fires the final `acrossai_mcp_oauth_legacy_dcr_purged` action after all three tables have completed their per-table steps (see TASK-2 + TASK-3 for their versions):
> ```php
> protected function upgrade_to_<new-version>(): bool {
>     global $wpdb;
>     $table = $wpdb->prefix . 'acrossai_mcp_oauth_clients';
>
>     // Step 1 — add server_id as NULL-allowed if missing (idempotent).
>     $col_exists = $wpdb->get_col( $wpdb->prepare(
>         "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
>          WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'server_id'",
>         DB_NAME, $table
>     ) );
>     if ( empty( $col_exists ) ) {
>         $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `server_id` bigint(20) unsigned DEFAULT NULL" );
>     }
>
>     // Step 2 — backfill admin clients from `server-{id}-` prefix (idempotent).
>     // Per SEC-032-003 remediation (2026-07-21): guard against phantom-server-id assignments.
>     // Parsed server_id MUST match a real row in oauth_servers; otherwise leave NULL for Step 3 to purge.
>     $servers_table = $wpdb->prefix . 'acrossai_mcp_servers';
>     $wpdb->query(
>         "UPDATE `{$table}`
>          SET server_id = CAST( SUBSTRING_INDEX( SUBSTRING_INDEX( client_id, '-', 2 ), '-', -1 ) AS UNSIGNED )
>          WHERE server_id IS NULL
>            AND client_id LIKE 'server-%'
>            AND CAST( SUBSTRING_INDEX( SUBSTRING_INDEX( client_id, '-', 2 ), '-', -1 ) AS UNSIGNED )
>                IN ( SELECT id FROM `{$servers_table}` )"
>     );
>
>     // Step 3 — PURGE legacy DCR client rows (server_id still NULL after backfill).
>     // Per 2026-07-21 clarify Q3 (A-aggressive). Live AI-host sessions bound to these
>     // rows disconnect on next request; users re-authorize via standard OAuth flow.
>     // README + release-note warn operators (FR-025).
>     $clients_purged = (int) $wpdb->query(
>         "DELETE FROM `{$table}` WHERE server_id IS NULL"
>     );
>
>     // Step 4 — swap UNIQUE(client_id) → UNIQUE(client_id, server_id).
>     // Order: ADD composite FIRST so the table is never unconstrained.
>     $composite_exists = $wpdb->get_col( $wpdb->prepare(
>         "SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
>          WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = 'client_id_server_id'",
>         DB_NAME, $table
>     ) );
>     if ( empty( $composite_exists ) ) {
>         $wpdb->query( "ALTER TABLE `{$table}` ADD UNIQUE KEY `client_id_server_id` (`client_id`, `server_id`)" );
>     }
>
>     $legacy_exists = $wpdb->get_col( $wpdb->prepare(
>         "SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
>          WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = 'client_id'",
>         DB_NAME, $table
>     ) );
>     if ( ! empty( $legacy_exists ) ) {
>         $wpdb->query( "ALTER TABLE `{$table}` DROP INDEX `client_id`" );
>     }
>
>     // Step 5 — MODIFY server_id to NOT NULL (idempotent via IS_NULLABLE check).
>     $is_nullable = $wpdb->get_var( $wpdb->prepare(
>         "SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
>          WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'server_id'",
>         DB_NAME, $table
>     ) );
>     if ( 'YES' === $is_nullable ) {
>         $wpdb->query( "ALTER TABLE `{$table}` MODIFY `server_id` bigint(20) unsigned NOT NULL" );
>     }
>
>     // Step 6 — fire the aggregate legacy-purge observability action ONCE per upgrade run.
>     // TokensQuery + AuthCodesQuery expose their own $tokens_purged / $auth_codes_purged
>     // via a small helper method invoked here (they set instance state at the end of their
>     // own upgrade callbacks). Fire only if ANY of the three counts > 0.
>     $tokens_purged     = OAuthTokens\Table::instance()->get_last_purge_count();
>     $auth_codes_purged = OAuthAuthCodes\Table::instance()->get_last_purge_count();
>     if ( $clients_purged > 0 || $tokens_purged > 0 || $auth_codes_purged > 0 ) {
>         do_action(
>             'acrossai_mcp_oauth_legacy_dcr_purged',
>             $clients_purged,
>             $tokens_purged,
>             $auth_codes_purged
>         );
>     }
>
>     return true;
> }
> ```
>
> **Ordering note**: because BerlinDB fires `$upgrades` per-table in registration order, TASK-2 (OAuthTokens) and TASK-3 (OAuthAuthCodes) MUST run their callbacks BEFORE the OAuthClients callback so the JOIN backfill can still resolve `client_id → server_id` before the client-side purge deletes the source rows. Register Table version bumps in that order in `Main::reconcile_database_schemas()`, OR structure each per-table callback so tokens/auth-codes upgrades JOIN against the client row BEFORE step 3 fires (safest: TASK-2 + TASK-3 backfill FIRST when their own callbacks run, and TASK-1 client-side purge only touches client rows whose descendants have already been JOIN-backfilled or purged). See TASK-2 / TASK-3 callback shapes for the explicit ordering.
>
> Row.php delta — add `public $server_id = 0;` alongside the existing properties. Cast in constructor (`$this->server_id = (int) $this->server_id;`) — B18 defense. Add to `to_array()`. Post-migration invariant: `server_id` is never NULL on any row (SQL-level enforcement via the NOT NULL constraint from Step 5); the `(int)` cast is defensive-only and should never encounter a NULL value during normal operation.
>
> Query.php delta — see the plan file for the four method-level changes. Preserve every existing public method signature EXCEPT `find_by_client_id` which gains a required `int $server_id` param (breaking change to callers within `includes/OAuth/*` — must update every call site in TASK-5/TASK-6).
>
> Do NOT bump other Tables' `$version` in this TASK — MCPServer/CliAuthLog stay on their current versions.
>
> ---
>
> **TASK-2 — OAuthTokens schema + backfill via JOIN**
>
> Files:
> - `includes/Database/OAuthTokens/Schema.php` (delta: append `server_id` column + add `KEY(server_id)` for query performance)
> - `includes/Database/OAuthTokens/Table.php` (delta: bump `$version` + register `$upgrades` + implement callback)
> - `includes/Database/OAuthTokens/Row.php` (delta: add property + cast + `to_array()` entry)
> - `includes/Database/OAuthTokens/Query.php` (delta: `revoke_by_client_id` gains required `int $server_id` param; `get_active_user_ids_by_client_id` renamed to `..._and_server_id` with required `int $server_id` param; `query` accepts `server_id` filter. `revoke_by_user_id` STAYS UNCHANGED — user-deletion cascade is site-wide.)
>
> Schema.php delta — append `server_id` column entry AND add a KEY:
> ```php
> array(
>     'name'    => 'server_id_client_id',
>     'type'    => 'key',
>     'columns' => array( 'server_id', 'client_id' ),
> ),
> ```
> (Composite index on `(server_id, client_id)` accelerates the primary lookup pattern `WHERE server_id = ? AND client_id = ?`.)
>
> Schema.php `$columns` entry — declare NOT NULL final state, same as TASK-1:
> ```php
> array(
>     'name'       => 'server_id',
>     'type'       => 'bigint',
>     'length'     => '20',
>     'unsigned'   => true,
>     'allow_null' => false,
> ),
> ```
>
> Table.php delta — bump `$version`. Callback body — six ordered steps + small `get_last_purge_count()` helper for the aggregate signal fired by TASK-1's callback:
> ```php
> protected int $last_purge_count = 0;
>
> public function get_last_purge_count(): int {
>     return $this->last_purge_count;
> }
>
> protected function upgrade_to_<new-version>(): bool {
>     global $wpdb;
>     $table         = $wpdb->prefix . 'acrossai_mcp_oauth_tokens';
>     $clients_table = $wpdb->prefix . 'acrossai_mcp_oauth_clients';
>
>     // Step 1 — add column as NULL-allowed (idempotent).
>     $col_exists = $wpdb->get_col( $wpdb->prepare(
>         "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
>          WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'server_id'",
>         DB_NAME, $table
>     ) );
>     if ( empty( $col_exists ) ) {
>         $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `server_id` bigint(20) unsigned DEFAULT NULL" );
>     }
>
>     // Step 2 — add composite KEY (idempotent).
>     $idx_exists = $wpdb->get_col( $wpdb->prepare(
>         "SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
>          WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = 'server_id_client_id'",
>         DB_NAME, $table
>     ) );
>     if ( empty( $idx_exists ) ) {
>         $wpdb->query( "ALTER TABLE `{$table}` ADD KEY `server_id_client_id` (`server_id`, `client_id`)" );
>     }
>
>     // Step 3 — backfill from clients.server_id via JOIN (idempotent — only touches NULL rows).
>     // MUST run BEFORE the OAuthClients callback's purge step so the JOIN can still resolve
>     // client_id → server_id. Table registration order in Main::reconcile_database_schemas()
>     // enforces this: OAuthTokens + OAuthAuthCodes register before OAuthClients.
>     $wpdb->query(
>         "UPDATE `{$table}` t
>          INNER JOIN `{$clients_table}` c ON t.client_id = c.client_id
>          SET t.server_id = c.server_id
>          WHERE t.server_id IS NULL AND c.server_id IS NOT NULL"
>     );
>
>     // Step 4 — PURGE token rows still holding server_id IS NULL after backfill.
>     // These belong to legacy DCR clients that will themselves be purged by the
>     // OAuthClients callback in step 3 of that callback. Per Q3 (A-aggressive).
>     $this->last_purge_count = (int) $wpdb->query(
>         "DELETE FROM `{$table}` WHERE server_id IS NULL"
>     );
>
>     // Step 5 — MODIFY server_id to NOT NULL (idempotent).
>     $is_nullable = $wpdb->get_var( $wpdb->prepare(
>         "SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
>          WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'server_id'",
>         DB_NAME, $table
>     ) );
>     if ( 'YES' === $is_nullable ) {
>         $wpdb->query( "ALTER TABLE `{$table}` MODIFY `server_id` bigint(20) unsigned NOT NULL" );
>     }
>
>     return true;
> }
> ```
>
> Row.php + Query.php deltas — same pattern as TASK-1. **CRITICAL**: `revoke_by_user_id( int $user_id )` signature does NOT change — `UserLifecycle::on_user_deleted` is a site-wide cascade per FR-042. Add a regression test in TASK-8. Post-migration invariant: no token row can have NULL `server_id` (SQL-enforced).
>
> ---
>
> **TASK-3 — OAuthAuthCodes schema + backfill via JOIN + purge + NOT NULL**
>
> Same shape as TASK-2 applied to `wp_acrossai_mcp_oauth_auth_codes`. All five steps identical (add NULL column → backfill via JOIN → purge NULL rows → MODIFY to NOT NULL → expose `get_last_purge_count()` helper for TASK-1's aggregate signal). Backfill JOIN is via `client_id` → `oauth_clients.server_id` (identical pattern to tokens). MUST run before OAuthClients callback for the same reason as TASK-2 (JOIN needs client rows still present). `delete_by_user_id` stays site-wide-cascade shape (mirrors `revoke_by_user_id`). Schema.php declares `allow_null => false` for the final NOT NULL state.
>
> ---
>
> **TASK-4 — OAuth flow propagation (Authorize → TokenController → Refresh)**
>
> Files:
> - `includes/OAuth/AuthorizationController.php`
> - `includes/OAuth/TokenController.php`
> - `includes/OAuth/Repositories/AuthCodeRepository.php`
> - `includes/OAuth/Repositories/AccessTokenRepository.php`
> - `includes/OAuth/Repositories/RefreshTokenRepository.php`
>
> Read `AuthorizationController::render_consent()` + `handle_consent_post()` — locate the auth-code persistence call. Add `server_id` resolution from the RFC 8707 `resource` param (already parsed for audience binding — reuse that resolution). Pass through to `AuthCodeRepository::create()`.
>
> Read `TokenController::handle_authorization_code()` (line ~106+) and `handle_refresh_token()` (line ~200+). At token creation:
> - `authorization_code` grant: `$server_id = (int) $auth_code_row->server_id;` — copy onto the emitted token row.
> - `refresh_token` grant: `$server_id = (int) $prior_token_row->server_id;` — copy onto the emitted new token row.
> - Add defense-in-depth check: verify `$client_row->server_id === $auth_code_row->server_id` — return `invalid_grant` on mismatch (should be impossible, but guards a data-corruption case).
>
> Update the three Repository classes' `create()` methods to accept `server_id` in the `$data` array and persist it. Update every caller in the two Controllers to include `server_id` in the `create()` payload.
>
> ---
>
> **TASK-5 — ConnectorAdminController REST validation**
>
> File: `includes/OAuth/ConnectorAdminController.php`
>
> Three endpoint handlers need updating:
>
> `handle_revoke_client_tokens( \WP_REST_Request $request )` — update. Every 403 return MUST fire `acrossai_mcp_oauth_cross_server_attempted` immediately BEFORE the WP_Error (D19 fail-open observability per FR-023, 4-arg signature per SEC-032-001 remediation 2026-07-21):
> ```php
> $server_id = (int) $request->get_param( 'server_id' );
> $client_id = self::sanitize_client_id( (string) $request->get_param( 'client_id' ) );
> if ( $server_id <= 0 || '' === $client_id ) {
>     return new \WP_Error( 'invalid_request', __( 'Missing server_id or client_id.', 'acrossai-mcp-manager' ), array( 'status' => 400 ) );
> }
>
> // Verify the client actually belongs to the caller's server.
> $client_row = ClientsQuery::instance()->find_by_client_id_and_server_id( $client_id, $server_id );
> if ( null === $client_row ) {
>     // FR-023 (4-arg signature) — fire observability signal BEFORE returning WP_Error.
>     // We DO NOT include the actual owning server_id — that would leak cross-server binding to any listener
>     // (any WordPress plugin can hook this action; the arg would recreate the oracle F032 exists to close).
>     // Operators who need the owning server for forensic analysis can query the DB from within their listener.
>     do_action(
>         'acrossai_mcp_oauth_cross_server_attempted',
>         $client_id,
>         $server_id,          // requested
>         get_current_user_id(),
>         time()
>     );
>
>     return new \WP_Error(
>         'acrossai_mcp_oauth_cross_server',
>         __( 'This client does not belong to the specified server.', 'acrossai-mcp-manager' ),
>         array( 'status' => 403 )  // 403 not 404 — do NOT leak cross-server existence.
>     );
> }
>
> $revoked_ids = TokensQuery::instance()->revoke_by_client_id( $client_id, $server_id );
> // ... existing action-fire loop, unchanged ...
> return new \WP_REST_Response( array( 'revoked_count' => count( $revoked_ids ) ), 200 );
> ```
>
> Note (per SEC-032-001 remediation, 2026-07-21): the originally-planned `ClientsQuery::find_by_client_id_any_server( string ): ?Row` helper has been REMOVED from the design. It would have populated `$server_id_actual` for the do_action fire, but any listener on the action would receive the actual owning server_id — a cross-server oracle. The revised 4-arg action does not disclose the owning server; the helper has no remaining caller and MUST NOT be added.
>
> `handle_delete_client( \WP_REST_Request $request )` — same validation shape prepended, including the same 4-arg `do_action( 'acrossai_mcp_oauth_cross_server_attempted', ... )` fire before the 403 return, then existing delete flow.
>
> `handle_revoke_connector_tokens( \WP_REST_Request $request )` — already extracts `server_id` for admin clients. Extend the DCR loop in `mass_revoke_connector_tokens()` (lines 304-330) to filter by `server_id`:
> ```php
> foreach ( ClientsQuery::instance()->find_dcr_clients( $server_id ) as $dcr_row ) {
>     if ( $profile->matches_dcr_client( ... ) ) {
>         $dcr_clients[] = $dcr_row;
>     }
> }
> ```
> (The `$server_id` param on `find_dcr_clients` is optional per TASK-1; when passed it filters the DCR rows to only those bound to the target server. Post-migration invariant: no row can have `server_id IS NULL` — the NOT NULL constraint from TASK-1 Step 5 enforces this at the SQL layer — so every DCR row visible to this method is bound to some server.)
>
> ---
>
> **TASK-6 — ClientRegistrationController server_id capture**
>
> File: `includes/OAuth/ClientRegistrationController.php`
>
> `handle_register( \WP_REST_Request $request )` (the DCR endpoint) — resolve `server_id` from the RFC 8707 `resource` param in the DCR body. Includes FR-028 race guard (SEC-032-005 remediation):
> ```php
> // FR-028 — pre-migration race guard. Verify server_id column exists before ANY work.
> // If the plugin was just upgraded and Main::reconcile_database_schemas() hasn't fired yet,
> // the column is absent — refusing to INSERT prevents silent destruction by the auto-purge step.
> if ( ! self::oauth_clients_server_id_column_exists() ) {
>     return new \WP_Error(
>         'service_unavailable',
>         __( 'Server initialization in progress; please retry in a few seconds.', 'acrossai-mcp-manager' ),
>         array( 'status' => 503 )
>     );
> }
>
> $resource = (string) $request->get_param( 'resource' );
> if ( '' === $resource ) {
>     return new \WP_Error( 'invalid_target', 'RFC 8707 resource parameter is required.', array( 'status' => 400 ) );
> }
> $server_id = self::resolve_server_id_from_resource_url( $resource );
> if ( $server_id <= 0 ) {
>     return new \WP_Error( 'invalid_target', 'Resource URL does not resolve to a known MCP server.', array( 'status' => 400 ) );
> }
> // ... existing DCR validation ...
> $client_id = self::generate_dcr_client_id();  // e.g., 'claude-desktop-abc123'
> ClientsQuery::instance()->add_item( array(
>     'client_id'       => $client_id,
>     'server_id'       => $server_id,   // ← NEW
>     'client_name'     => $client_name,
>     'connector_slug'  => $connector_slug,
>     // ... existing fields ...
> ) );
> ```
>
> New helper `resolve_server_id_from_resource_url( string $resource ): int` — TWO-STEP CHECK per FR-027 (SEC-032-002 remediation 2026-07-21). Origin verification precedes path resolution:
>
> ```php
> public static function resolve_server_id_from_resource_url( string $resource ): int {
>     // Step 1 — ORIGIN VERIFICATION (FR-027 / SEC-032-002).
>     // Reject any URL whose scheme+host+port does not match home_url().
>     $resource_parts = wp_parse_url( $resource );
>     $home_parts     = wp_parse_url( home_url() );
>     if (
>         empty( $resource_parts['scheme'] ) || empty( $resource_parts['host'] )
>         || $resource_parts['scheme'] !== $home_parts['scheme']
>         || strcasecmp( $resource_parts['host'], $home_parts['host'] ) !== 0
>         || ( $resource_parts['port'] ?? null ) !== ( $home_parts['port'] ?? null )
>     ) {
>         // Fire observability signal for differentiation from path-mismatch.
>         do_action(
>             'acrossai_mcp_oauth_dcr_resource_url_origin_mismatch',
>             $resource,
>             get_current_user_id(),
>             time()
>         );
>         return 0;  // Caller converts to WP_Error 'invalid_target' 400.
>     }
>
>     // Step 2 — PATH RESOLUTION via MCPServerQuery route matcher.
>     // Reuse CurrentServerHolder::capture_from_request() normalization for trailing-slash + port variance.
>     $server_slug = CurrentServerHolder::instance()->extract_server_slug_from_url( $resource );
>     if ( '' === $server_slug ) {
>         return 0;
>     }
>     $server_row = MCPServerQuery::instance()->query( array( 'slug' => $server_slug, 'number' => 1 ) );
>     return null === $server_row ? 0 : (int) $server_row[0]->id;
> }
>
> // FR-028 helper — per-request cached column existence check.
> private static ?bool $server_id_column_exists_cache = null;
> private static function oauth_clients_server_id_column_exists(): bool {
>     if ( null !== self::$server_id_column_exists_cache ) {
>         return self::$server_id_column_exists_cache;
>     }
>     global $wpdb;
>     $table = $wpdb->prefix . 'acrossai_mcp_oauth_clients';
>     $col = $wpdb->get_col( $wpdb->prepare(
>         "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
>          WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'server_id'",
>         DB_NAME, $table
>     ) );
>     self::$server_id_column_exists_cache = ! empty( $col );
>     return self::$server_id_column_exists_cache;
> }
> ```
>
> `handle_admin_generate()` — already knows `server_id` from the admin form context. Add `server_id` to the `ClientsQuery::instance()->add_item()` payload. Also inherits FR-028 race guard (same `oauth_clients_server_id_column_exists()` gate at top). No new resolution logic needed for the server_id capture itself.
>
> ---
>
> **TASK-7 — AIConnectorsTab UI + F024 JS**
>
> Files:
> - `admin/Partials/ServerTabs/AIConnectorsTab.php` (`render_connections_panel()` — lines 271-341)
> - `src/js/connectors-nested-tabs.js` (or whatever the F024 nested-tabs entry is named — see `webpack.config.js` for the exact entry filename)
>
> PHP: every Revoke/Delete button HTML must include `data-acrossai-server-id="<?php echo esc_attr( (int) $server_id ); ?>"` in addition to the existing `data-acrossai-client-id`. Also switch the "authorized users" listing from `ClientRepository::get_active_user_ids_by_client_id( $client_id )` (currently the read-side leak) to `ClientRepository::get_active_user_ids_by_client_id_and_server_id( $client_id, $server_id )` — closes the read-side cross-server display leak surfaced during audit.
>
> JS: read both data attributes on button click, include both in the fetch body:
> ```js
> const body = {
>     client_id: btn.dataset.acrossaiClientId,
>     server_id: parseInt( btn.dataset.acrossaiServerId, 10 ),
> };
> ```
> Response handler: on 403 `acrossai_mcp_oauth_cross_server`, surface a distinct error message ("This action can only be performed for the server that owns this client — refresh the page and try again."). Distinct from generic 403 so operators understand it's not a permissions issue.
>
> ---
>
> **TASK-8 — Callers verification sweep + PHPUnit isolation tests**
>
> Files:
> - `tests/phpunit/OAuth/PerServerIsolationTest.php` (NEW)
> - `tests/phpunit/Database/OAuthClients/PerServerColumnUpgradeTest.php` (NEW)
> - `tests/phpunit/Database/OAuthTokens/PerServerColumnUpgradeTest.php` (NEW)
> - `tests/phpunit/Database/OAuthAuthCodes/PerServerColumnUpgradeTest.php` (NEW)
> - Existing OAuth tests: audit + update any test that seeds OAuth rows to include `server_id`.
>
> `PerServerIsolationTest.php` — 8 tests:
> 1. `test_server_a_revoke_does_not_touch_server_b_tokens` — seed two servers + two clients (`server-1-claude-ai-abc`, `server-2-claude-ai-xyz`) + 2 tokens per client; invoke `handle_revoke_client_tokens( server_id=1, client_id='server-1-claude-ai-abc' )`; assert server 2's tokens still `revoked = 0`.
> 2. `test_server_a_delete_does_not_touch_server_b_client_row` — same seed; invoke delete on server 1's client; assert server 2's client row still present in `oauth_clients`.
> 3. `test_same_dcr_connector_registers_on_two_servers_as_two_rows` — invoke `handle_register` twice with different `resource` URLs mapping to server 1 and server 2 but same `client_name`; assert two distinct rows in `oauth_clients` both with `client_name = 'Claude Desktop'` but distinct `server_id`.
> 4. `test_rest_endpoint_returns_403_on_server_id_mismatch` — invoke `handle_revoke_client_tokens( server_id=1, client_id='server-2-...' )`; assert `WP_Error` with code `acrossai_mcp_oauth_cross_server` + status 403.
> 5. `test_authorized_users_listing_filters_by_server_id` — seed two servers with two users each (User A on server 1, User B on server 2, both holding tokens for the same client_id — impossible after F032's composite unique BUT possible during migration mid-state seeded before purge); assert `get_active_user_ids_by_client_id_and_server_id( client_id, server_id=1 )` returns only User A.
> 6. `test_user_deletion_still_cascades_across_all_servers` — regression: seed 2 servers with tokens for User A on both; call `UserLifecycle::on_user_deleted( user_id_A )`; assert BOTH servers' tokens revoked. (Protects the FR-042 site-wide semantic against accidental server-scoping regression.)
> 7. `test_cross_server_403_fires_observability_action` — NEW (per FR-023 / SC-007). Attach a spy listener to `acrossai_mcp_oauth_cross_server_attempted` before invoking N mismatched requests across all three endpoints (revoke-client-tokens, delete-client, revoke-connector-tokens). Assert action fires exactly N times with **4-arg** shape `($client_id, $server_id_requested, $user_id, $timestamp)` (per SEC-032-001 remediation) — verify the actual owning server_id is NOT among the args (assertion: `count( $spy->last_args ) === 4`) and correct values per request.
> 9. `test_dcr_rejects_attacker_origin_url` — NEW (per FR-027 / SC-010, SEC-032-002 remediation). Seed server 1 with slug `server-1-slug`. Invoke `handle_register` with `resource = 'https://evil.attacker.com/wp-json/mcp/server-1-slug'` (path matches, origin is attacker-controlled). Assert (a) 400 `invalid_target` returned, (b) `oauth_clients` row count unchanged, (c) `acrossai_mcp_oauth_dcr_resource_url_origin_mismatch` action fired exactly once with `$resource` matching submitted value.
> 10. `test_backfill_skips_orphan_server_ids` — NEW (per FR-005 amendment / SC-011, SEC-032-003 remediation). Seed a legacy admin client with `client_id = 'server-99999-orphan-abc'` (server_id 99999 does NOT exist in oauth_servers). Rewind version + run `maybe_upgrade()`. Assert (a) row not present post-migration (correctly PURGED because server_id remains NULL), (b) `SELECT COUNT WHERE server_id NOT IN (SELECT id FROM oauth_servers)` returns 0.
> 11. `test_dcr_returns_503_when_column_absent` — NEW (per FR-028 / SC-012, SEC-032-005 remediation). Simulate pre-migration state: `ALTER TABLE oauth_clients DROP COLUMN server_id` on the test DB. Invoke `handle_register` with a valid resource URL. Assert (a) 503 `service_unavailable` returned, (b) no new client row created. Trigger `Main::reconcile_database_schemas()` on next admin_init. Re-invoke `handle_register`. Assert 201 success + client row created with correct `server_id`.

(Test 8 — `test_legacy_dcr_purge_on_upgrade_fires_observability_action` — retained in numeric position 8 below.)
> 8. `test_legacy_dcr_purge_on_upgrade_fires_observability_action` — NEW (per FR-024 / SC-008). Seed M legacy DCR clients + P tokens + Q auth codes (all with `server_id IS NULL`) into a pre-migration schema state; attach spy listener to `acrossai_mcp_oauth_legacy_dcr_purged`; run the three upgrade callbacks; assert action fires exactly once with args `(M, P, Q)` and that all three tables have `SELECT COUNT WHERE server_id IS NULL = 0` post-run.
>
> Three `PerServerColumnUpgradeTest` files — mirror the F030 `MCPServer/PermissionOverrideColumnUpgradeTest` shape. Each verifies (a) column present with correct type + `IS_NULLABLE = 'NO'` post-migration, (b) fresh insert with `server_id` succeeds AND fresh insert without `server_id` FAILS with MySQL constraint violation (SC-009), (c) idempotent re-run (`maybe_upgrade()` twice → no ALTER, no duplicate ALTER errors, no second `acrossai_mcp_oauth_legacy_dcr_purged` fire), (d) dropped-column recovery (drop the column, rewind version, re-run → column restored + backfill re-applied + NOT NULL re-applied), (e) mid-migration crash simulation (kill between ADD COLUMN and ADD UNIQUE) — next run completes all remaining steps without error.
>
> Post-flight grep audit — re-run the TASK-0 pre-flight grep and verify every hit either uses the new server-scoped signature OR is `UserLifecycle::on_user_deleted` (the one allowed exception).
>
> ---
>
> **TASK-9 — Memory hygiene + changelog**
>
> Files: `README.txt`, `docs/memory/DECISIONS.md`, `docs/memory/BUGS.md`, `docs/memory/INDEX.md`, `docs/memory/WORKLOG.md`, `docs/planings-tasks/README.md`
>
> Read `PATTERN-MEMORY-SUPERSESSION-VS-ANNOTATION` (sibling plugin's `docs/memory/ARCHITECTURE.md`) before editing.
>
> `README.txt` — add an Unreleased changelog bullet with prominent operator warning (per FR-025):
> ```
> * **Feature 032 — OAuth per-server scoping (SECURITY FIX + BREAKING
>   CHANGE for legacy DCR sessions).**
>
>   ⚠️ **BEFORE UPGRADE**: this release deletes any pre-F032 DCR-registered
>   OAuth client rows (those without a `server-{id}-` prefix, e.g. legacy
>   Claude.ai / ChatGPT / Cursor / Cline connections) and their tokens +
>   auth codes as part of the upgrade migration. Any live AI-host session
>   bound to a legacy DCR row will disconnect on the next request; affected
>   users must re-run the OAuth authorize flow to reconnect. All post-F032
>   DCR registrations are per-server and unaffected.
>
>   Fix: closes a cross-server privilege-escalation gap where an admin on
>   Server A's Connectors tab could revoke or delete Server B's clients/tokens
>   by modifying the client_id in the outbound REST body. Also fixes a
>   read-side display leak in the "authorized users" listing.
>
>   Adds server_id column (NOT NULL) to oauth_clients / oauth_tokens /
>   oauth_auth_codes via D28 3-part contract; changes UNIQUE(client_id) →
>   UNIQUE(client_id, server_id) so the same DCR connector can be registered
>   on multiple servers as independent rows; requires + validates server_id
>   on every mutating REST endpoint (returns 403 acrossai_mcp_oauth_cross_server
>   on mismatch + fires do_action('acrossai_mcp_oauth_cross_server_attempted', ...)
>   for operator observability). Legacy DCR row purge fires
>   do_action('acrossai_mcp_oauth_legacy_dcr_purged', $clients, $tokens, $auth_codes)
>   once per upgrade. Bumps oauth_clients / oauth_tokens / oauth_auth_codes
>   `$version` by 0.0.1 each with matching `$upgrades` callbacks.
> ```
>
> `docs/memory/DECISIONS.md` — new active entry:
> ```
> DEC-F032-OAUTH-SERVER-ID-FIRST-CLASS (Active — Feature 032)
> server_id is a first-class NOT NULL column on wp_acrossai_mcp_oauth_clients,
> wp_acrossai_mcp_oauth_tokens, and wp_acrossai_mcp_oauth_auth_codes.
> UNIQUE(client_id) is replaced with composite UNIQUE(client_id, server_id)
> so the same DCR connector can be registered on N servers as N rows.
> Every mutating REST endpoint MUST require server_id in the request body
> AND verify the referenced client/token/auth_code row's server_id matches
> — return WP_Error 'acrossai_mcp_oauth_cross_server' status 403 on
> mismatch (403 not 404 — do not leak cross-server existence) AND fire
> do_action('acrossai_mcp_oauth_cross_server_attempted', $client_id,
> $server_id_requested, $server_id_actual, $user_id, $timestamp) BEFORE
> the WP_Error return (D19 fail-open observability). OAuth flow propagates
> server_id end-to-end: AuthorizationController resolves from RFC 8707
> resource → AuthCodeRepository persists → TokenController copies onto
> token at code-exchange → refresh flow inherits from prior token.
> `UserLifecycle::on_user_deleted()` STAYS server-neutral — user deletion
> is site-wide per FR-042. Column nullability: NOT NULL from day one
> (added as NULL transiently, MODIFYed to NOT NULL as final upgrade step
> after purge). Legacy pre-F032 DCR rows (server_id IS NULL after backfill)
> are AUTO-PURGED during the upgrade callback — this reverses the initial
> "preserve" position after weighing tradeoffs (per 2026-07-21 clarify Q3,
> A-aggressive form). Live AI-host sessions bound to purged rows disconnect
> on next request; users re-authorize. README + release-note warn operators
> before upgrade (FR-025). Purge counts fire
> do_action('acrossai_mcp_oauth_legacy_dcr_purged', $clients, $tokens,
> $auth_codes) exactly once per upgrade run. Feature ships unconditionally
> — no acrossai_mcp_manager_oauth_per_server_scoping_enabled feature flag;
> rollback is via composer downgrade if operationally required.
> ```
>
> `docs/memory/BUGS.md` — new active entry:
> ```
> B-CROSS-SERVER-BYPASS-VIA-CLIENT-ID-ONLY (Active — Feature 032; generalizable)
> Any per-tenant admin REST endpoint that accepts only a tenant-scoped
> identifier (client_id, resource_id, entity_id, ...) without validating
> tenant binding is a cross-tenant privilege escalation. F032 fixed this
> for the OAuth layer; the pattern applies to any future admin endpoint.
> Prevention grep gate: for any file under includes/OAuth/ OR any file
> exposing a mutating REST endpoint with a tenant-scoped resource,
> `grep -rn 'get_param.*client_id\|get_param.*token' <file>` — every hit
> MUST be accompanied by a matching server_id (or equivalent tenant)
> validation before the referenced row is touched. Companion to D28
> (the schema-drift pattern) — this is the runtime-validation counterpart.
> ```
>
> `docs/memory/INDEX.md` — three new rows (`DEC-F032-...`, `B-CROSS-SERVER-...`, WORKLOG entry).
>
> `docs/memory/WORKLOG.md` — F032 milestone entry (Why durable / Future mistake prevented / Evidence / Where to look).
>
> `docs/planings-tasks/README.md` — append row for `032-oauth-per-server-scoping.md`.
>
> ---
>
> **CONSTRAINTS**
>
> - **Do not rename tables.** `acrossai_mcp_oauth_clients`, `..._oauth_tokens`, `..._oauth_auth_codes` are the backwards-compat contract with any prior install's data.
> - **Do not rename `db_version_key` option names.** Same rationale.
> - **Do PURGE legacy DCR rows with `server_id = NULL`** during the upgrade callback (per 2026-07-21 clarify Q3 — reverses the original "do not delete" position). Live AI-host sessions bound to purged rows disconnect on next request; README + release-note MUST warn operators (FR-025). Rationale: leaves the tables fully server-scoped post-migration with zero orphan rows and enables the `NOT NULL` constraint (per Q4); the alternative preserves a permanent legacy surface that re-opens the read-side leak and blocks the schema-level invariant. Ordering matters: TASK-2/TASK-3 purge callbacks run BEFORE TASK-1's client purge so token/auth-code JOIN backfill can still resolve `client_id → server_id`.
> - **Do NOT ship behind a feature flag.** No `acrossai_mcp_manager_oauth_per_server_scoping_enabled` toggle (per 2026-07-21 clarify Q2). Cross-server bypass is a security vulnerability, not a documented feature; a legacy-mode toggle would legitimise it and permanently branch the code.
> - **Do declare `server_id` as `NOT NULL` in Schema.php.** (per 2026-07-21 clarify Q4.) The upgrade callback adds it as NULL transiently, backfills, purges, then MODIFYs to NOT NULL — final state matches the Schema.php declaration so BerlinDB's SHOW CREATE TABLE diff stays green.
> - **Do add `server_id` to `oauth_clients` FIRST (registration order in `Main::reconcile_database_schemas()`)?** NO — reverse: OAuthTokens + OAuthAuthCodes callbacks MUST run BEFORE OAuthClients so their JOIN backfill can still resolve `client_id → server_id` before the client-side purge deletes the source rows.
> - **Do not add `server_id` to `wp_acrossai_mcp_oauth_audit`.** Audit rows already record `server_id` (per F029). Don't re-do work.
> - **Do not touch `UserLifecycle::on_user_deleted()` cascade shape.** User deletion is site-wide per FR-042; adding server-scoping there breaks the safety semantic. Regression test (isolation TASK-8 test #6) protects against this.
> - **Do not change `TokensQuery::revoke_by_user_id( int $user_id )` signature.** Same reason as above.
> - **Do not drop `UNIQUE(client_id)` BEFORE creating `UNIQUE(client_id, server_id)`.** Mid-migration constraint gap. Order: ADD composite → verify → DROP standalone. Callback wraps this in the correct order (TASK-1 code snippet).
> - **Do not use 404 on cross-server mismatch — use 403.** 404 would leak "this client exists on some other server"; 403 is opaque to cross-server existence.
> - **Do fire `acrossai_mcp_oauth_cross_server_attempted` BEFORE every 403 return** (per FR-023). **4-arg signature** (`$client_id, $server_id_requested, $user_id, $timestamp`) — MUST NOT include the actual owning server_id (per SEC-032-001 remediation 2026-07-21). The action is fire-and-forget; the plugin does NOT require a listener. Operators may attach any logger.
> - **Do verify DCR resource URL origin against `home_url()` BEFORE path resolution** (per FR-027 / SEC-032-002 remediation 2026-07-21). `resolve_server_id_from_resource_url` MUST perform Step 1 origin match before Step 2 MCPServerQuery lookup. Origin mismatch fires `acrossai_mcp_oauth_dcr_resource_url_origin_mismatch` action + returns 0 (caller converts to `WP_Error invalid_target 400`).
> - **Do guard backfill UPDATE against phantom-server-id assignments** (per FR-005 amendment / SEC-032-003 remediation 2026-07-21). The Step 2 backfill UPDATE MUST include `AND CAST(...) IN (SELECT id FROM {$wpdb->prefix}acrossai_mcp_servers)` — otherwise legacy admin clients whose prefix points at a deleted server survive as phantom-server rows.
> - **Do reject DCR requests with 503 when `server_id` column is absent** (per FR-028 / SEC-032-005 remediation 2026-07-21). `handle_register` MUST verify column presence via cached `INFORMATION_SCHEMA` lookup BEFORE INSERT. Missing column → 503 `service_unavailable`. Prevents silent destruction of race-window registrations by the auto-purge step.
> - **Do NOT add `find_by_client_id_any_server` helper** (per SEC-032-001 remediation 2026-07-21). The originally-planned observability helper was removed to close the cross-server oracle in the do_action arg list. Grep gate: `grep -rn 'find_by_client_id_any_server' includes/` MUST return zero matches.
> - **BerlinDB Schema `$columns` MUST match the `upgrade_to_<v>()` DDL final state byte-for-byte** (types, lengths, nullability) — otherwise BerlinDB's diff engine fires an ALTER on production installs. Reviewer MUST verify with `SHOW CREATE TABLE` on any install with existing data.
> - **`$indexes` MUST replicate every existing PRIMARY KEY / UNIQUE / KEY from the current CREATE TABLE statements — verbatim index names, EXCEPT** the `client_id` UNIQUE which is intentionally replaced by `client_id_server_id` composite on `oauth_clients` (documented above).
> - **Every task must leave PHPStan level 8 + PHPCS individually green before moving to the next.** Constitution §VII per-task gating applies.
> - **`oauth` PHPUnit suite must remain WP-loaded** (requires `bootstrap-wp.php`) — new tests inherit this.
> - **Grep after every task** for stale references to the old `client_id`-only signatures. The Final full-repo audit at the bottom MUST return zero matches.

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

### TASK-1 — OAuthClients schema + backfill (with orphan-server guard) + purge + composite UNIQUE + NOT NULL
- [ ] `wp option get wpdb_acrossai_mcp_oauth_clients_version` returns the bumped version.
- [ ] `wp db query "DESCRIBE wp_acrossai_mcp_oauth_clients"` shows `server_id bigint(20) unsigned NOT NULL` (no `NULL` in the Null column).
- [ ] `wp db query "SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'wp_acrossai_mcp_oauth_clients' AND COLUMN_NAME = 'server_id'"` returns `NO`.
- [ ] `wp db query "SHOW INDEX FROM wp_acrossai_mcp_oauth_clients WHERE Key_name = 'client_id_server_id'"` returns 2 rows (composite index).
- [ ] `wp db query "SHOW INDEX FROM wp_acrossai_mcp_oauth_clients WHERE Key_name = 'client_id'"` returns 0 rows (standalone index dropped).
- [ ] `wp db query "SELECT COUNT(*) FROM wp_acrossai_mcp_oauth_clients WHERE client_id LIKE 'server-%' AND server_id IS NULL"` returns 0 (all admin clients backfilled).
- [ ] `wp db query "SELECT COUNT(*) FROM wp_acrossai_mcp_oauth_clients WHERE server_id IS NULL"` returns 0 (post-migration invariant: no NULL rows in any of the three tables).
- [ ] `wp db query "SELECT COUNT(*) FROM wp_acrossai_mcp_oauth_clients WHERE server_id NOT IN (SELECT id FROM wp_acrossai_mcp_servers)"` returns 0 (SC-011 orphan-server invariant per SEC-032-003 remediation).
- [ ] `wp db query "SELECT COUNT(*) FROM wp_acrossai_mcp_oauth_clients WHERE client_id NOT LIKE 'server-%'"` returns 0 IFF no post-F032 DCR clients have been registered yet (any remaining non-prefix rows are new DCR registrations bound to a server via the DCR endpoint; those are correct).
- [ ] Attempted `INSERT INTO wp_acrossai_mcp_oauth_clients (client_id, ...) VALUES (...)` omitting `server_id` fails with MySQL constraint violation.
- [ ] `acrossai_mcp_oauth_legacy_dcr_purged` action fires exactly once during the upgrade run (verified via a temporary logger or Query Monitor); does NOT fire on subsequent admin page loads.
- [ ] Re-run: `wp option delete wpdb_acrossai_mcp_oauth_clients_version && wp cron event run acrossai_mcp_manager_maybe_upgrade` (or reload any admin page) — no `ALTER` re-issued, no `SHOW WARNINGS`, no `debug.log` noise, no second `acrossai_mcp_oauth_legacy_dcr_purged` fire.

### TASK-2 — OAuthTokens schema + backfill + purge + NOT NULL
- [ ] Column + composite index present per DESCRIBE + SHOW INDEX.
- [ ] `SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'wp_acrossai_mcp_oauth_tokens' AND COLUMN_NAME = 'server_id'` returns `NO`.
- [ ] `SELECT COUNT(*) FROM wp_acrossai_mcp_oauth_tokens WHERE server_id IS NULL` returns 0.
- [ ] Backfill successful — `SELECT COUNT(*) FROM wp_acrossai_mcp_oauth_tokens t JOIN wp_acrossai_mcp_oauth_clients c ON t.client_id = c.client_id WHERE t.server_id != c.server_id` returns 0.
- [ ] Attempted INSERT without `server_id` fails with MySQL constraint violation.
- [ ] `revoke_by_user_id()` signature unchanged (grep confirms).

### TASK-3 — OAuthAuthCodes schema + backfill + purge + NOT NULL
- [ ] Column + composite index present.
- [ ] `SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'wp_acrossai_mcp_oauth_auth_codes' AND COLUMN_NAME = 'server_id'` returns `NO`.
- [ ] `SELECT COUNT(*) FROM wp_acrossai_mcp_oauth_auth_codes WHERE server_id IS NULL` returns 0.
- [ ] Attempted INSERT without `server_id` fails with MySQL constraint violation.
- [ ] Backfill JOIN worked (analogous SELECT COUNT with JOIN on clients).

### TASK-4 — OAuth flow propagation
- [ ] Fresh authorize flow: consent → grant → check `oauth_auth_codes.server_id` on the emitted row equals the requested resource's server.
- [ ] Code exchange: check `oauth_tokens.server_id` equals the auth_code's server_id.
- [ ] Refresh: check new token's server_id equals prior token's server_id.

### TASK-5 — ConnectorAdminController REST validation + observability
- [ ] `POST /oauth/revoke-client-tokens` with matching `server_id + client_id` → 200 + tokens revoked.
- [ ] `POST /oauth/revoke-client-tokens` with mismatched server_id → 403 `acrossai_mcp_oauth_cross_server` AND `acrossai_mcp_oauth_cross_server_attempted` action fires with correct args before the 403 return (verified via spy listener).
- [ ] `POST /oauth/delete-client` — same two cases + observability fire.
- [ ] `POST /oauth/revoke-connector-tokens` (nuclear) — verify DCR-side filter now respects server_id + verify observability fires on any DCR-side cross-server hit.
- [ ] Grep: every `return new \WP_Error( 'acrossai_mcp_oauth_cross_server'` in `includes/OAuth/ConnectorAdminController.php` is preceded within 10 lines by a `do_action( 'acrossai_mcp_oauth_cross_server_attempted'` call.

### TASK-6 — ClientRegistrationController
- [ ] DCR request with valid `resource` URL → new client row with resolved `server_id`.
- [ ] DCR request with unresolvable `resource` (path doesn't match any server) → 400 `invalid_target`.
- [ ] DCR request with attacker-origin `resource` URL (e.g. `https://evil.com/wp-json/mcp/server-1-slug`) → 400 `invalid_target` + `acrossai_mcp_oauth_dcr_resource_url_origin_mismatch` action fires (per FR-027 / SEC-032-002).
- [ ] DCR request during pre-migration race window (server_id column absent) → 503 `service_unavailable` (per FR-028 / SEC-032-005). After migration completes, retry succeeds.
- [ ] `handle_admin_generate` still persists `server_id` on the new client row.
- [ ] Grep: `grep -rn 'find_by_client_id_any_server' includes/` returns ZERO matches (per SEC-032-001 remediation — helper MUST NOT be added).

### TASK-7 — UI + JS
- [ ] Every Revoke/Delete button in the Connections panel has both `data-acrossai-server-id` and `data-acrossai-client-id` attributes.
- [ ] JS fetch body includes both fields.
- [ ] The 403 error case surfaces the specific "cross-server" message, not a generic error.
- [ ] "Authorized users" listing shows only users bound to the current server.

### TASK-8 — Tests
- [ ] All 8 `PerServerIsolationTest` methods pass (6 original + 2 observability tests per FR-023 / FR-024).
- [ ] All 3 `PerServerColumnUpgradeTest` files pass their 5 assertions each (column present + IS_NULLABLE = 'NO', fresh insert with/without server_id, idempotent, drop-and-restore, mid-migration crash recovery).
- [ ] `UserLifecycle::on_user_deleted` regression test passes.
- [ ] Pre-flight grep is empty (every hit either uses new signature OR is the allowed `on_user_deleted` exception).

### TASK-9 — Release notes + memory hygiene
- [ ] `README.txt` Unreleased changelog contains the Feature 032 bullet.
- [ ] `docs/memory/DECISIONS.md`: `DEC-F032-OAUTH-SERVER-ID-FIRST-CLASS` present.
- [ ] `docs/memory/BUGS.md`: `B-CROSS-SERVER-BYPASS-VIA-CLIENT-ID-ONLY` present.
- [ ] `docs/memory/WORKLOG.md`: F032 milestone entry added.
- [ ] `docs/memory/INDEX.md`: 3 new rows appended (decision + bug + worklog).
- [ ] `docs/planings-tasks/README.md` lists `032-oauth-per-server-scoping.md`.

### Final full-repo audit (blocker before merge)

```bash
grep -rEn 'revoke_by_client_id\s*\(|delete_by_id\s*\(|get_active_user_ids_by_client_id\s*\(|find_by_client_id\s*\(' \
    --include='*.php' \
    includes/ admin/ public/
```

- [ ] Every hit either passes a `server_id` param (new signature) OR is a call inside a test fixture / migration path with a documented exception. Zero unchecked `client_id`-only calls in production code.

```bash
grep -rEn 'get_param\s*\(\s*.client_id.\s*\)|get_param\s*\(\s*.token.\s*\)' \
    --include='*.php' \
    includes/OAuth/
```

- [ ] Every hit is immediately followed by a `server_id` extraction + validation. B-CROSS-SERVER-BYPASS-VIA-CLIENT-ID-ONLY grep gate.

```bash
grep -rEn "return new \\\\WP_Error\(\s*'acrossai_mcp_oauth_cross_server'" \
    --include='*.php' \
    includes/OAuth/
```

- [ ] Every hit is preceded within 10 lines by a `do_action( 'acrossai_mcp_oauth_cross_server_attempted'` call (FR-023 observability grep gate).

```bash
grep -rn 'server_id IS NULL' \
    --include='*.php' \
    includes/Database/OAuth
```

- [ ] Every hit is inside an upgrade callback and is one of: (a) idempotency guard for the ALTER (`WHERE server_id IS NULL` on backfill UPDATE), (b) the PURGE `DELETE FROM ... WHERE server_id IS NULL`. Zero occurrences in production runtime code (Query, Row, Repository, Controller) — those paths assume `server_id` is never NULL post-migration.

```bash
grep -rn 'find_by_client_id_any_server' \
    --include='*.php' \
    includes/ admin/ public/ tests/
```

- [ ] ZERO matches (per SEC-032-001 remediation 2026-07-21 — the originally-planned helper was removed to close the cross-server oracle in the observability action arg list).

```bash
grep -rn "do_action.*acrossai_mcp_oauth_cross_server_attempted" \
    --include='*.php' \
    includes/OAuth/
```

- [ ] Every hit passes exactly 4 args after the action name: `$client_id, $server_id_requested, $user_id, $timestamp`. The actual owning server_id MUST NOT be among the args (per SEC-032-001 remediation).

```bash
grep -rEn 'resolve_server_id_from_resource_url' \
    --include='*.php' \
    includes/OAuth/
```

- [ ] The helper definition MUST contain BOTH `wp_parse_url` + comparison against `home_url()` (Step 1 origin verification per FR-027 / SEC-032-002) AND the MCPServerQuery lookup (Step 2 path resolution). If Step 1 is absent, the SEC-032-002 remediation has been lost.

```bash
grep -rn "'service_unavailable'\|status.*503" \
    --include='*.php' \
    includes/OAuth/ClientRegistrationController.php
```

- [ ] At least one 503 response exists inside `handle_register` guarded by a column-existence check on `oauth_clients.server_id` (per FR-028 / SEC-032-005 remediation).

### Quality gates (all must be green before commit)

- [ ] PHPStan level 8 on every file touched — zero errors.
- [ ] PHPCS on every file touched — zero errors.
- [ ] `composer run test -- --testsuite oauth` — all existing tests + 10 new F032 tests pass.
- [ ] `composer dump-autoload` — succeeds with zero warnings.
- [ ] `SHOW CREATE TABLE wp_acrossai_mcp_oauth_clients` matches `Schema.php` + `Table::upgrade_to_<v>()` DDL byte-for-byte (types, lengths, defaults, indexes).
- [ ] Same check for `wp_acrossai_mcp_oauth_tokens` + `wp_acrossai_mcp_oauth_auth_codes`.

---

## Pre-flight Attestation (SEC-032-001 / T001)

**To be captured** via `/speckit-security-review-plan` during Phase 3. F032 modifies live OAuth-issued token data (backfill sets `server_id` on existing rows, then purges any row still holding `server_id IS NULL` — including any live-session legacy DCR row and all its descendants) and changes REST endpoint semantics (mutating endpoints now return 403 on previously-successful cross-server calls + fire `acrossai_mcp_oauth_cross_server_attempted` for observability). Operator awareness required to confirm they understand:

1. Any live AI-host session (Claude.ai / ChatGPT / Cursor / Cline / etc.) currently bound to a pre-F032 DCR row **will disconnect** on the next request after upgrade and require the user to re-authorize via the standard OAuth flow. README + release-note carry a prominent warning per FR-025.
2. Any admin workflow currently relying on the cross-server bypass semantic (revoking / deleting Server B's clients from Server A's Connectors tab) will start returning 403 `acrossai_mcp_oauth_cross_server` after upgrade. This was never a documented feature; the 403 is the correct signal.

**Rollout**: unconditional (per 2026-07-21 clarify Q2). No `acrossai_mcp_manager_oauth_per_server_scoping_enabled` feature flag. Rollback is via composer package downgrade if operationally required.

**Attesting user**: (TBD at plan phase)
**Validity window**: attestation date → Feature 032 merge.
