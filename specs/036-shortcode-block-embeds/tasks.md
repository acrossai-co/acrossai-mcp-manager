---
description: "Task list for F037 — Per-Server Shortcode + Block Embeds Tab"
---

# Tasks: F037 Per-Server Shortcode + Block Embeds Tab

**Input**: Design documents from `specs/036-shortcode-block-embeds/`
**Prerequisites**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md), [contracts/AbstractEmbedTransport.contract.md](./contracts/AbstractEmbedTransport.contract.md), [security-constraints.md](./security-constraints.md), [memory-synthesis.md](./memory-synthesis.md)

**Tests**: REQUIRED per AGENTS.md + Constitution §VII Definition of Done. All test tasks below are mandatory.

**Organization**: Grouped by user story (US1 P1 admin gate, US2 P2 third-party extensibility, US3 P1 security cascade). Each phase independently testable. Security-review findings SEC-037-001..006 folded in as `[SEC-037-XXX]`-tagged tasks (matches F035 pattern). Every grep gate spelled out with exact command per B26 defense.

## Format: `[ID] [P?] [Story?] Description`

- **[P]**: Different files, no dependencies on incomplete tasks — safe to fan out
- **[Story]**: `[US1]` / `[US2]` / `[US3]` — required for user-story phase tasks

## Path Conventions

- Source (new): `includes/Embeds/*.php`, `includes/Database/ServerEmbedTransports/*.php`, `admin/Partials/ServerTabs/EmbedsTab.php`, `public/Renderers/EmbedBlock/EmbedBlockRenderer.php`
- Source (modified): `includes/Main.php` (4 wire points), `includes/Database/MCPServer/{Schema,Table}.php` (D28 bump), `admin/Partials/ServerTabs/Registry.php` (one-line addition), `phpunit.xml.dist`, `.github/workflows/phpunit.yml`, `README.txt`, `CLAUDE.md`
- Tests (new): `tests/phpunit/Embeds/*.php`
- Docs (new): `specs/036-shortcode-block-embeds/contracts/EmbedBlockRenderer.contract.md`
- Memory: `docs/memory/{INDEX.md, DECISIONS.md}` — no new entries planned (see T039)

---

## Phase 1: Setup (Pre-Flight Baseline)

**Purpose**: Capture pre-implementation state so post-implementation grep gates + byte-identical render assertions have a comparison baseline.

- [x] T001 Capture pre-flight baseline to `/tmp/f037-preflight.txt`:
  - `grep -rEn '\bAbstractEmbedTransport\b|\bEmbedsTab\b|\bEmbedBlockRenderer\b|\bacrossai_mcp_embed_' --include='*.php' .` — MUST return zero hits (nothing exists yet).
  - `grep -rEn 'protected \$version' includes/Database/MCPServer/Table.php` — record current `$version` value (expect `'1.1.2'`; F037 bumps to `'1.1.3'`).
  - `grep -c 'extends AbstractServerTab' admin/Partials/ServerTabs/*.php` — record current tab count (baseline for verifying EmbedsTab addition).
  - Visit `?page=acrossai_mcp_manager&action=edit&server=1` on a local install; capture rendered tab-nav HTML (view-source) as `/tmp/f037-tabs-preflight.html`. Used by T043 to verify Embeds tab appears in nav post-implementation without disturbing other tabs.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: BerlinDB schema additions per D28 3-part contract + new junction table module + PHPUnit suite registration. Blocks US1–US3 tests from running in isolation.

### D28 3-part contract for `embeds_enabled` column on existing `wp_acrossai_mcp_servers`

- [x] T002 In `includes/Database/MCPServer/Schema.php` add new column definition to `$columns` array: `embeds_enabled` of type `TINYINT(1) UNSIGNED NOT NULL DEFAULT 0`. Match existing column-definition style in the same array. Do NOT touch any other column definition.
- [x] T003 In `includes/Database/MCPServer/Table.php`:
  - Bump `protected $version = '1.1.2';` → `protected $version = '1.1.3';`
  - Add to `protected $upgrades = [ ... ];` array: `'1.1.3' => 'upgrade_to_1_1_3'`
  - Add new `protected function upgrade_to_1_1_3(): bool` method: idempotent `INFORMATION_SCHEMA.COLUMNS` existence check on the `embeds_enabled` column; if absent, `$wpdb->query( "ALTER TABLE {$this->table_name} ADD COLUMN embeds_enabled TINYINT(1) UNSIGNED NOT NULL DEFAULT 0" )`; return `bool` per BerlinDB `$upgrades` contract. Match F029 `upgrade_to_1_0_1` and F032 `upgrade_to_1_0_1` shapes exactly.

### New BerlinDB module `includes/Database/ServerEmbedTransports/`

- [x] T004 [P] Create `includes/Database/ServerEmbedTransports/Schema.php`:
  - Namespace `AcrossAI_MCP_Manager\Includes\Database\ServerEmbedTransports`
  - `final class Schema extends \BerlinDB\Database\Kern\Schema`
  - `public $columns` — 6 columns matching data-model.md §2:
    - `id` — BIGINT UNSIGNED AUTO_INCREMENT primary
    - `server_id` — BIGINT UNSIGNED NOT NULL
    - `transport_key` — VARCHAR(64) NOT NULL
    - `is_enabled` — TINYINT(1) UNSIGNED NOT NULL DEFAULT 0
    - `date_created` — DATETIME with `'flags' => ['created']`
    - `date_modified` — DATETIME with `'flags' => ['modified']` — **B21 defense** (NOT `'date_updated'`)
  - Indexes: `UNIQUE(server_id, transport_key)` + `KEY(server_id)`
- [x] T005 [P] Create `includes/Database/ServerEmbedTransports/Table.php`:
  - `final class Table extends \BerlinDB\Database\Kern\Table`
  - `protected $name = 'acrossai_mcp_server_embed_transports';`
  - `protected $version = '1.0.0';`
  - `protected $upgrades = array();` (empty on initial ship)
  - Include F011 phantom-version guard in overridden `maybe_upgrade()`
- [x] T006 [P] Create `includes/Database/ServerEmbedTransports/Row.php`:
  - `final class Row extends \BerlinDB\Database\Kern\Row`
  - Typed properties: `int $id`, `int $server_id`, `string $transport_key`, `int $is_enabled`, `string $date_created`, `string $date_modified`
- [x] T007 [P] Create `includes/Database/ServerEmbedTransports/Query.php`:
  - `final class Query extends \BerlinDB\Database\Kern\Query`
  - `protected $item_shape = Row::class;`
  - `public static function is_enabled_for_server( int $server_id, string $transport_key ): bool` — SELECT single row with matching `(server_id, transport_key)` + `is_enabled = 1`; return `true` iff found; **B18 defense** — cast `(int) $row->is_enabled` before strict compare
  - `public static function set_enabled_for_server( int $server_id, string $transport_key, bool $enabled ): bool` — UPSERT presence row (INSERT ... ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled))
  - `public static function get_all_for_server( int $server_id ): array<string, bool>` — SELECT all rows for the server; return `[transport_key => is_enabled]` map
  - `public static function delete_by_server_id( int $server_id ): int` — DELETE all rows for the server; return count for FR-017 server-deletion cleanup
  - `public static function delete_by_transport_keys( array $transport_keys ): int` — DELETE rows whose `transport_key` is in the array; **B39 defense** — parameterized `%s` placeholders in `IN(…)` clause (NOT string interpolation), match F032 `revoke_by_client_id_and_user_id` shape
  - `public static function distinct_transport_keys(): array<string>` — SELECT DISTINCT `transport_key` values; used by `garbage_collect_orphans()`

### Wire the new Table + reconciliation

- [x] T008 In `includes/Main.php` `load_hooks()` method: instantiate `\AcrossAI_MCP_Manager\Includes\Database\ServerEmbedTransports\Table::instance()` at request time per DEC-BERLINDB-TABLE-REQUEST-BOOT. Match existing sibling instantiation shape for `MCPServer\Table`, `OAuthTokens\Table`, etc.
- [x] T009 In `includes/Main.php` `reconcile_database_schemas()` method: add `ServerEmbedTransports\Table::instance()->maybe_upgrade()` call. Match existing F029/F032 sibling pattern. Also verify `MCPServer\Table::instance()->maybe_upgrade()` is called (should already exist) — that's what fires the T003 `upgrade_to_1_1_3` callback on `admin_init@3` per D28.

### PHPUnit suite registration + CI step

- [x] T010 [P] In `phpunit.xml.dist` add new `<testsuite name="embeds">` element pointing at `tests/phpunit/Embeds/`. Match `discovery` (F035) suite XML shape. Bootstrap: `tests/bootstrap-wp.php` (per FR-015 / A18 rationale — BerlinDB + WP options + shortcode API + F015 wrapper all need WP context).
- [x] T011 [P] In `.github/workflows/phpunit.yml` add new CI step "Run PHPUnit — F037 embeds suite" with `continue-on-error: true` (mirror F035 discovery-suite step shape). Command: `vendor/bin/phpunit --bootstrap tests/bootstrap-wp.php --testsuite embeds`. Insert immediately after the discovery suite step.
- [x] T012 [P] Create test directory: `mkdir -p tests/phpunit/Embeds/`.

**Checkpoint**: BerlinDB migration path complete + test suite scaffold ready. Manual smoke: on a local install, deactivate + reactivate the plugin OR visit any wp-admin page to trigger `admin_init@3`; verify `SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='wp_acrossai_mcp_servers' AND COLUMN_NAME='embeds_enabled'` returns 1 row AND `SHOW TABLES LIKE 'wp_acrossai_mcp_server_embed_transports'` returns 1 row.

---

## Phase 3: User Story 1 — Site administrator gates shortcode output per server (Priority: P1) 🎯 MVP

**Goal**: Site admin can enable/disable frontend shortcode output per server + per transport via the Embeds tab. Every value change fires observability actions per FR-024.

**Independent Test**: Fresh install → new server → Embeds tab renders with master UNCHECKED + 3 disabled sub-toggles. Check master + MCP Clients sub-toggle + save → verify `wp_acrossai_mcp_servers.embeds_enabled = 1` for that server + presence row `(server_id, 'client', 1)` in junction table + observability actions fired exactly once each. Cascade + shortcode not yet wired at this checkpoint.

### Tests for User Story 1 (write FIRST, ensure FAIL against pre-implementation)

- [x] T013 [P] [US1] Write `tests/phpunit/Embeds/AbstractEmbedTransportTest.php` with `WP_UnitTestCase`. In `setUp()` call `AbstractEmbedTransport::flush_cache()` for R2 isolation + reset ServerEmbedTransports table via truncate helper. Cover:
  - `test_get_all_registered_transports_default_state()` — 3 built-in transports returned in priority order `[npm, client, ai_connector]`.
  - `test_filter_can_append_valid_transport()` — mu-style filter appends `FakeEmbedTransport` (key `test-fake`, priority 50); assert appears in returned list at expected slot.
  - `test_invalid_fqn_silently_dropped()` — filter contributes non-string / missing class / non-subclass FQN; each dropped silently.
  - `test_bad_slug_regex_dropped_and_doing_it_wrong_fires()` — subclass returns key `Bad-Key-With-CAPS` OR empty string OR 65-char string; each dropped + `_doing_it_wrong` fires under `WP_DEBUG=true`.
  - `test_duplicate_slug_later_wins()` — two subclasses same key; later wins; `_doing_it_wrong` fires.
  - `test_get_priority_type_coercion()` [SEC-037-002] — subclass returns `null` / `false` / string from `get_priority()`; enumeration MUST NOT fatal + coerced to `(int)` in comparator; `_doing_it_wrong` fires under `WP_DEBUG=true`.
  - `test_is_enabled_for_server_state_matrix()` — 4 combinations:
    - master OFF, transport row absent → false
    - master OFF, transport row present (is_enabled=1) → false (master gate short-circuits)
    - master ON, transport row absent → false
    - master ON, transport row present (is_enabled=1) → true
  - `test_is_enabled_returns_false_on_string_tinyint()` [B18] — insert row via raw `$wpdb` (returns TINYINT as string); assert helper still returns bool via `(int)` cast.
  - `test_memoization_and_flush_cache()` [R2] — call `is_enabled_for_server()` twice; assert second call hits cache (via internal state or query-count assertion); call `flush_cache()`; next call re-queries.
  - `test_garbage_collect_orphans_removes_only_orphans()` — insert 5 rows (3 built-in transport keys + 2 fake keys not registered); call `garbage_collect_orphans()`; assert returns 2 + only fake-key rows deleted.
  - `test_garbage_collect_orphans_idempotent()` — second call returns 0.
- [x] T014 [P] [US1] Write `tests/phpunit/Embeds/ConcreteTransportsTest.php` — data-provider parameterized over 3 built-in classes `[NpmEmbedTransport, ClientEmbedTransport, AiConnectorEmbedTransport]`. For each, assert `get_transport_key()` matches `[npm, client, ai_connector]` respectively, `get_checkbox_label()` returns non-empty translated string with the correct text domain, `get_priority()` matches `[10, 20, 30]`, class is `final`.
- [ ] T015 [P] [US1] Write `tests/phpunit/Embeds/EmbedsTabSaveHandlerTest.php` with `WP_UnitTestCase`. `setUp()` creates admin user + sets current user + creates a server row. Cover:
  - `test_save_requires_nonce()` — POST without nonce → save rejected + no DB change.
  - `test_save_requires_manage_options()` — POST with valid nonce but current user is subscriber → save rejected.
  - `test_save_persists_master_and_transport_state()` — POST with valid nonce + admin user + master + 2 transport checkboxes checked → assert `embeds_enabled = 1` on server row + presence rows in junction table.
  - `test_missing_checkbox_treated_as_off()` [SEC-037-003] — POST with valid nonce + master field ABSENT (not present in `$_POST`) → assert `embeds_enabled = 0` (interpreted as unchecked per HTML form convention).
  - `test_cross_server_nonce_replay_rejected()` [SEC-037-001] — create nonce for `server=A` (`acrossai_mcp_embeds_save_1`); submit against `server=B` save handler (`acrossai_mcp_embeds_save_2`) → save rejected + server B unchanged.
  - `test_observability_master_toggle_fires_once()` [SC-010] — subscribe to `acrossai_mcp_embed_master_toggled` action; save with master 0 → 1; assert action fired exactly once with correct args.
  - `test_observability_transport_toggle_fires_per_row()` [SC-010] — subscribe to `acrossai_mcp_embed_transport_toggled` action; save toggling 3 transports; assert action fired exactly 3 times.
  - `test_observability_master_and_transports_both_fire()` [SC-010] — save toggling master + 3 transports; assert 1× master + 3× transport events.
  - `test_observability_no_op_save_emits_nothing()` [SC-010] — save with zero value changes; assert 0× master + 0× transport events.
  - `test_observability_listener_throw_does_not_break_others()` [R3] — register 2 listeners on the same action; first throws; second still fires; DB write committed.

### Implementation for User Story 1

- [x] T016 [US1] Create `includes/Embeds/AbstractEmbedTransport.php` — the abstract base per contracts/AbstractEmbedTransport.contract.md:
  - Namespace `AcrossAI_MCP_Manager\Includes\Embeds`
  - `abstract class AbstractEmbedTransport`
  - `public const DEFAULT_TRANSPORT_CLASSES = [ NpmEmbedTransport::class, ClientEmbedTransport::class, AiConnectorEmbedTransport::class ];`
  - `private static array $enabled_cache = [];` (R2 memoization)
  - `abstract public function get_transport_key(): string;`
  - `abstract public function get_checkbox_label(): string;`
  - `public function get_priority(): int { return 100; }`
  - `public function get_description(): string { return ''; }`
  - `public static function flush_cache(): void { self::$enabled_cache = []; }`
  - Explicit `use` imports for `Includes\Database\{MCPServer, ServerEmbedTransports}\Query` per A6.
- [x] T017 [US1] Add `public static function get_all_registered_transports(): array` to `AbstractEmbedTransport.php` per contract §Static Method Contracts:
  - Fire `apply_filters( 'acrossai_mcp_embed_transports', self::DEFAULT_TRANSPORT_CLASSES )`
  - For each FQN: silent-skip if not string/class-missing/not-subclass; instantiate; validate `get_transport_key()` matches `/\A[a-z0-9-]{1,64}\z/` (silent-skip + `_doing_it_wrong( 'AbstractEmbedTransport::get_all_registered_transports', __( '...', 'acrossai-mcp-manager' ), '0.1.10' )` under `WP_DEBUG`); dedup by key later-wins with `_doing_it_wrong` on duplicates.
  - Mirrors F034 `AbstractMCPClient::get_all_registered_clients()` line-for-line.
- [x] T018 [SEC-037-002] [US1] Inside T017 `usort` comparator: cast `(int) $a->get_priority()` + `(int) $b->get_priority()` BEFORE comparison per SEC-037-002 hardening. Optionally emit `_doing_it_wrong` under `WP_DEBUG` when `is_int($a->get_priority())` fails. Exact snippet per contract file:
  ```php
  usort( $instances, static function ( AbstractEmbedTransport $a, AbstractEmbedTransport $b ): int {
      $pa = (int) $a->get_priority();
      $pb = (int) $b->get_priority();
      return ( $pa <=> $pb ) ?: strcmp( $a->get_transport_key(), $b->get_transport_key() );
  } );
  ```
- [x] T019 [US1] Add `public static function is_enabled_for_server( int $server_id, string $transport_key ): bool` to `AbstractEmbedTransport.php` per contract §Static Method Contracts:
  - Check `self::$enabled_cache["{$server_id}:{$transport_key}"]` — return cached if present.
  - Two-check cascade: (1) SELECT `embeds_enabled` FROM `wp_acrossai_mcp_servers` WHERE id = `$server_id` — cast `(int)` for B18 defense; false if 0 or missing. (2) `ServerEmbedTransports\Query::is_enabled_for_server( $server_id, $transport_key )` — returns bool.
  - Cache result + return.
- [x] T020 [US1] Add `public static function garbage_collect_orphans(): int` to `AbstractEmbedTransport.php` per contract + FR-023:
  - `$known = array_map( fn( $t ) => $t->get_transport_key(), self::get_all_registered_transports() );`
  - `$stored = ServerEmbedTransports\Query::distinct_transport_keys();`
  - `$orphans = array_diff( $stored, $known );`
  - `if ( empty( $orphans ) ) return 0;`
  - Return `ServerEmbedTransports\Query::delete_by_transport_keys( $orphans )`.
- [x] T021 [P] [US1] Create `includes/Embeds/NpmEmbedTransport.php` — `final class NpmEmbedTransport extends AbstractEmbedTransport` — override `get_transport_key(): string { return 'npm'; }` + `get_checkbox_label(): string { return __( 'NPM Methods', 'acrossai-mcp-manager' ); }` + `get_priority(): int { return 10; }`. ~15 lines.
- [x] T022 [P] [US1] Create `includes/Embeds/ClientEmbedTransport.php` — same shape, key `'client'`, label "MCP Clients", priority 20.
- [x] T023 [P] [US1] Create `includes/Embeds/AiConnectorEmbedTransport.php` — same shape, key `'ai_connector'`, label "AI Connectors", priority 30.
- [x] T024 [US1] Create `admin/Partials/ServerTabs/EmbedsTab.php`:
  - `final class EmbedsTab extends AbstractServerTab` (F013 hierarchy)
  - `public function slug(): string { return 'embeds'; }`
  - `public function label(): string { return __( 'Embeds', 'acrossai-mcp-manager' ); }`
  - `public function priority(): int { return 90; }` — slot between AccessControl and Danger Zone (Danger Zone is 100)
  - `protected function render_body( array $server ): void` — DEV5 hand-rolled form: `wp_nonce_field( 'acrossai_mcp_embeds_save_' . $server['id'] )` + master checkbox row + iterate `AbstractEmbedTransport::get_all_registered_transports()` and render one row per transport (using `checked()` + `esc_html()` + `esc_attr()`). Include `disabled` attribute on transport checkboxes when master is unchecked (progressive enhancement — no JS required for correctness).
- [x] T025 [SEC-037-001] [US1] Create `public function handle_save( array $server ): void` on `EmbedsTab` OR wire save to `admin/Partials/Settings.php` per existing sibling-tab convention:
  - Verify nonce: `wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'acrossai_mcp_embeds_save_' . $server['id'] )` — **server-scoped per SEC-037-001**.
  - Verify capability: `current_user_can( 'manage_options' )` → else silently no-op or `wp_die` per sibling-tab convention.
  - Read master value: `(bool) absint( $_POST['embeds_enabled'] ?? 0 )` — **absent field = OFF per SEC-037-003**.
  - Read transport array: `wp_unslash( $_POST['embeds_transports'] ?? [] )` + iterate against `get_all_registered_transports()` list (source of truth); each transport gets checked against `isset($post_transports[$key])` — absent = OFF.
- [x] T026 [R3] [US1] Inside `handle_save()` — write master + transports, then fire observability actions:
  - IF master value changed → `try { do_action( 'acrossai_mcp_embed_master_toggled', $server_id, $new_master_enabled, get_current_user_id() ); } catch ( \Throwable $e ) { /* silently log via error_log; do not rethrow */ }` — **fail-forward per R3**.
  - For each transport whose value changed → same shape with `acrossai_mcp_embed_transport_toggled` action.
  - Actions fire ONLY on actual value transitions per Q3 clarification — no-op saves emit nothing.
- [x] T027 [US1] In `admin/Partials/ServerTabs/Registry.php` `all_tabs()` method: add `EmbedsTab::instance()` to the built-in array. Match existing sibling registration shape (one-line addition). Verify tab appears in the effective list via `Registry::instance()->for_server( $server )`.
- [x] T028 [US1] In `includes/Main.php` `define_admin_hooks()` method — no additional wiring needed for the tab itself (Registry handles dispatch). Verify the schema reconciliation callback (T009) is already wired at `admin_init@3`. Verify no other admin-side hooks needed.

**Checkpoint**: US1 tests (T013, T014, T015) go RED → GREEN. Fresh install: Embeds tab appears in server-edit page nav; toggling master + sub-toggles persists to DB; observability actions fire correctly. Shortcode + F015 cascade NOT yet wired (US3 territory).

---

## Phase 4: User Story 2 — Third-party plugin registers a custom transport category (Priority: P2)

**Goal**: Third-party plugins can extend `AbstractEmbedTransport` + register via `acrossai_mcp_embed_transports` filter to add a fourth (or Nth) transport category. All base-class enumeration + persistence + gate-lookup + observability logic applies transparently.

**Independent Test**: Register an mu-plugin with a `BuddyBossProfileEmbedTransport` (key `buddyboss-profile`, priority 40, custom label). Visit any server-edit → Embeds tab. Assert checkbox row appears with correct label in correct priority slot (between AI Connectors [30] and default third-party bucket [100]). Save with the new transport enabled. Assert `AbstractEmbedTransport::is_enabled_for_server( $server_id, 'buddyboss-profile' )` returns true. Deactivate the mu-plugin. Visit Embeds tab again — checkbox row disappears; junction row survives per Q2.

- [ ] T029 [US2] Write `tests/phpunit/Embeds/ThirdPartyTransportTest.php` with `WP_UnitTestCase`:
  - `test_third_party_transport_appears_in_enumeration()` — register `FakeThirdPartyTransport` via filter (key `test-third-party`, priority 40); call `get_all_registered_transports()`; assert 4 items returned with fake in expected slot (after ai_connector [30]).
  - `test_third_party_transport_end_to_end_persistence()` — register + save via EmbedsTab save handler + call `is_enabled_for_server()` — round-trip works.
  - `test_third_party_transport_orphan_survives_deregistration()` [Q2] — register + save + then deregister filter callback; call `get_all_registered_transports()` — 3 items only (fake dropped); `ServerEmbedTransports\Query::is_enabled_for_server()` still returns true (row survived).
  - `test_garbage_collect_orphans_prunes_deregistered_transport()` — same setup as above; call `garbage_collect_orphans()`; assert returns 1 + row deleted; `is_enabled_for_server()` now returns false.

**Checkpoint**: US2 test (T029) passes. Third-party extensibility verified end-to-end via automated test — no manual mu-plugin required for CI.

---

## Phase 5: User Story 3 — Frontend rendering respects the security cascade (Priority: P1)

**Goal**: `[acrossai_mcp_embed]` shortcode renders IFF all 3 gates pass: (1) master toggle ON, (2) per-transport toggle ON, (3) F015 access control allows current user (or F015 wrapper absent → fail-open per D19). Any gate miss → zero-byte silent no-render.

**Independent Test**: Fresh install + enabled master + enabled MCP Clients + F015 wrapper absent (or F015 present + allows current user) → shortcode `[acrossai_mcp_embed server="foo" category="client"]` renders. Toggle master OFF → renders nothing. Toggle master ON but MCP Clients OFF → renders nothing. F015 present + denies → renders nothing.

### Tests for User Story 3

- [ ] T030 [P] [US3] Write `tests/phpunit/Embeds/EmbedBlockRendererShortcodeTest.php` with `WP_UnitTestCase`:
  - `test_gate_cascade_matrix_12_combinations()` [SC-004] — parameterized data-provider over 12 combinations (master {ON, OFF} × per-transport {ON, OFF} × F015 {present-allows, present-denies, absent}). For each: assert shortcode output is non-empty IFF all 3 gates pass, else exactly zero bytes.
  - `test_f015_absent_fails_open()` — F015 class stubbed out of scope; master + per-transport ON; assert shortcode renders (fail-open per D19).
  - `test_hostile_dto_string_escaped_at_render()` [SEC-035-002 inheritance] — register `HostileEmbedTransport` + hostile F035 filter callback returning DTOs with `<script>alert(1)</script>` in `name` field; shortcode renders; assert `&lt;script&gt;` in output NOT raw `<script>`.
  - `test_unknown_server_slug_renders_nothing()` — shortcode with `server="nonexistent-slug"` → zero bytes.
  - `test_shortcode_render_filter_fires()` — subscribe to `acrossai_mcp_embed_render_html` filter; assert fires once per shortcode invocation with the pre-echo HTML string.

### Implementation for User Story 3

- [x] T031 [SEC-037-004] [US3] Create `specs/036-shortcode-block-embeds/contracts/EmbedBlockRenderer.contract.md` — dedicated contract file for the frontend renderer per SEC-037-004:
  - Shortcode signature + attribute list (`server`, `category`, `slug` optional)
  - 3-gate cascade order (master → per-transport → F015)
  - Escape function per DTO field (`name`/`description` → `esc_html`, `icon` → `esc_html` for emoji + `esc_url` for URL, `meta.command_template` → `esc_html` + `<code>` wrap, `meta.config_file` → `esc_html`, `meta.top_level_key` → `esc_html`, `meta.icon_url` → `esc_url`, `meta.class` → NOT rendered, internal only)
  - Filter hook `acrossai_mcp_embed_render_html` firing timing (post-render, pre-echo) + expected consumer contract (return string; may modify)
  - `<script>` interpolation policy per B36 — currently no inline JS; if added later, use `wp_json_encode()`.
- [x] T032 [US3] Create `public/Renderers/EmbedBlock/EmbedBlockRenderer.php`:
  - Namespace `AcrossAI_MCP_Manager\Public\Renderers\EmbedBlock`
  - `final class EmbedBlockRenderer` per D36
  - `private static ?self $_instance = null;` + `public static function instance(): self` + `private function __construct() {}` (singleton + private ctor per S6)
  - `public function render_shortcode( array $atts ): string` — the shortcode callback:
    1. Normalize atts: `shortcode_atts( [ 'server' => '', 'category' => '', 'slug' => '' ], $atts )`.
    2. Resolve server_id via `MCPServer\Query::get_by_slug( $atts['server'] )` — return '' if missing (silent).
    3. Gate 1: `AbstractEmbedTransport::is_enabled_for_server( $server_id, $atts['category'] )` — return '' if false.
    4. Gate 2: `class_exists( '\AcrossAI_MCP_Access_Control' ) ? \AcrossAI_MCP_Access_Control::user_has_server_access( get_current_user_id(), $server_id ) : true` — return '' if false; fail-open per D19 when class absent.
    5. Resolve DTO(s) via `ConnectionMethodRegistry::instance()->find( $atts['category'], $atts['slug'] )` (single-DTO) OR the appropriate `get_*_methods()` (whole-category).
    6. Render HTML via escape-at-boundary — see EmbedBlockRenderer.contract.md.
    7. `$html = apply_filters( 'acrossai_mcp_embed_render_html', $html, $atts, $server_id );`
    8. Return `$html`.
- [x] T033 [US3] In `includes/Main.php` `define_public_hooks()` method: wire `add_shortcode( 'acrossai_mcp_embed', [ EmbedBlockRenderer::instance(), 'render_shortcode' ] )` per A1. Match F016 `acrossai_mcp_npm_block` shortcode registration shape.

**Checkpoint**: US3 test (T030) passes. Shortcode gate cascade verified via 12-combination matrix + hostile-DTO XSS regression + F015 fail-open + unknown-server silent no-render. Ready for full end-to-end integration test.

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: Grep-gate verification, memory hygiene, changelog, quality gates, documentation callouts for SEC-037-003..006, manual verification.

### Grep-gate verification (SC-005, SC-006, A1 preservation, B21 defense)

- [x] T034 [SC-005] Run grep audit for delegation (F037 must NEVER re-fire F034/F021/F035 filters):
  - `grep -rEn 'apply_filters.*acrossai_mcp_(client_classes|manager_connector_profiles|npm_methods|connection_methods)' includes/Embeds/ admin/Partials/ServerTabs/EmbedsTab.php public/Renderers/EmbedBlock/` MUST return zero hits.
  - `grep -rEn '\bacrossai_mcp_(client_classes|manager_connector_profiles|npm_methods|connection_methods)\b' includes/Embeds/ admin/Partials/ServerTabs/EmbedsTab.php public/Renderers/EmbedBlock/` MUST return zero hits (bare-string form; B15 regex-completeness defense).
- [x] T035 [SC-006] Run grep audit for one-way layering:
  - `grep -rEn '\bConnectionMethodRegistry\b' includes/Embeds/` MUST return zero hits (`includes/Embeds/` is pure-domain; only `public/Renderers/EmbedBlock/` may import F035).
  - `grep -rEn 'use AcrossAI_MCP_Manager\\\\Public\\\\Discovery' includes/Embeds/` MUST return zero hits.
- [x] T036 [A1] Run grep audit for hook-registration preservation:
  - `grep -rEn 'add_filter|add_action|add_shortcode' includes/Embeds/ admin/Partials/ServerTabs/EmbedsTab.php public/Renderers/EmbedBlock/` MUST return zero hits (all wiring lives in `Main.php`).
- [x] T037 [B21] Run BerlinDB flags audit:
  - `grep -rn "'date_updated'" includes/Database/ServerEmbedTransports/` MUST return zero hits (`modified` flag not `date_updated` per B21 defense).

### Documentation callouts

- [ ] T038 [SEC-037-005] In `contracts/AbstractEmbedTransport.contract.md` §Observability Actions + `data-model.md` §4 + `quickstart.md` §3: expand the `$user_id` semantic note documenting `$user_id = 0` as "non-user context (WP-CLI, cron, WP internal)" per SEC-037-005. Recommend audit consumers reject or annotate `$user_id = 0` events separately.
- [ ] T039 [SEC-037-006] In `spec.md` §Assumptions: add note about Phase 2 block-editor block MUST use `<ServerSideRender>` (or equivalent server-round-trip mechanism) to preserve gate cascade at editor-preview render time per SEC-037-006. Client-side-only preview would bypass gates silently.

### Memory hygiene + changelog + pointer

- [x] T040 [P] In `README.txt` under `== Changelog ==` `= Unreleased =` (or create if F035's release has closed the section): add F037 bullet describing the feature summary — Embeds tab, per-server + per-transport gates, `AbstractEmbedTransport` base class + 3 built-ins, `[acrossai_mcp_embed]` shortcode, 3-gate cascade, 2 observability actions, `garbage_collect_orphans()` helper, new junction table + column bump, new `embeds` PHPUnit suite. Note SEC-037-001 (server-scoped nonce) + SEC-037-002 (comparator coercion) remediations shipped inline.
- [x] T041 [P] In `CLAUDE.md` (root): verify `Active plan:` pointer already points at `specs/036-shortcode-block-embeds/plan.md` (set during plan phase — no-op if correct).
- [ ] T042 Memory hygiene — no new decision entries required. F037 applies existing durable memory: D35 (canonical enumeration mirror), D36 (`final class`), DEC-SERVER-TAB-CLASS-HIERARCHY (F013 tab pattern), DEC-TOOL-SELECTION-PRESENCE-MODEL (junction table shape), D28 (schema-drift reconciliation), DEV5 (hand-rolled form 4th consumer), SEC-013-008 (silent-drop invalid contributions), B18/B21/B32/B34/B37 (defenses codified in tests). SEC-037-002 comparator hardening applies to F034 too — flag as tech debt candidate for a follow-up PR (retrofit `AbstractMCPClient::get_all_registered_clients()` with same defense); do NOT do it in this PR (scope creep).

### Quality gates

- [x] T043 [P] Run `vendor/bin/phpcs includes/Embeds/ includes/Database/ServerEmbedTransports/ admin/Partials/ServerTabs/EmbedsTab.php public/Renderers/EmbedBlock/ tests/phpunit/Embeds/` — MUST report zero errors and zero warnings.
- [x] T044 [P] Run `vendor/bin/phpstan analyse includes/Embeds/ includes/Database/ServerEmbedTransports/ admin/Partials/ServerTabs/EmbedsTab.php public/Renderers/EmbedBlock/ --memory-limit=4G --no-progress` — MUST report zero errors at level 8.
- [ ] T045 Run `vendor/bin/phpunit --bootstrap tests/bootstrap-wp.php --testsuite embeds` — new suite MUST be green. Also verify no regression in `--testsuite discovery`, `--testsuite renderers`, `--testsuite admin`, `--testsuite database`, `--testsuite oauth`, `--testsuite abilities`, `--testsuite mcp`, `--testsuite mcpclients`, `--testsuite cli-rest`. Depends on T043, T044.
- [ ] T046 Manual verification checklist:
  - (a) Fresh install → new server → visit Embeds tab. Assert master unchecked; 3 sub-toggles greyed. Check master → sub-toggles enable. Save. Reload. State persisted.
  - (b) Toggle MCP Clients ON, save. Place `[acrossai_mcp_embed server="<slug>" category="client"]` on a public frontend page. Verify block renders.
  - (c) Change category to `npm` (per-transport OFF). Verify frontend renders nothing.
  - (d) Toggle master OFF. Verify no shortcode renders regardless of per-transport state.
  - (e) Install F015 access-control plugin + deny current user for server → verify shortcode renders nothing (F015 wins cascade).
  - (f) Uninstall F015 → verify shortcode renders (fail-open per D19).
  - (g) Register mu-plugin exercising quickstart §1 + §2 (custom transport). Verify checkbox appears + save round-trips + `is_enabled_for_server()` returns correct value.
  - (h) Register mu-plugin exercising quickstart §3 (audit-log listeners). Trigger a save. Verify audit-log records the two granular events.
  - (i) Sanity SQL post-release: `SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME LIKE 'wp_acrossai_mcp_server%'` — confirm `embeds_enabled` on `servers` + full column set on `server_embed_transports`.
  - (j) Uninstall path smoke: enable F012 opt-in DELETE → uninstall → confirm junction table dropped + `embeds_enabled` column preserved per R4.
  - Depends on T045.

**Checkpoint**: All spec Success Criteria met. Ready for `/speckit-git-commit` + PR.

---

## Dependencies & Execution Order

### Phase dependencies

- **Setup (Phase 1)**: T001 first.
- **Foundational (Phase 2)**: Depends on T001. T002 → T003 sequential (both touch `MCPServer` module). T004–T007 parallel (4 different files in new module). T008 → T009 sequential (both touch `Main.php`). T010 + T011 parallel (different files). T012 trivial parallel.
- **US1 (Phase 3)**: Depends on Phase 2. Test tasks (T013, T014, T015) parallel-authorable; implementation blocked on them per TDD. T016 → T017 → T018 → T019 → T020 sequential (all touch `AbstractEmbedTransport.php`). T021, T022, T023 parallel (different files). T024 → T025 → T026 sequential (all touch `EmbedsTab.php`). T027 + T028 depend on T024.
- **US2 (Phase 4)**: Depends on Phase 3 (needs `AbstractEmbedTransport` fully implemented).
- **US3 (Phase 5)**: Depends on Phase 3 (needs `is_enabled_for_server()`); independent of Phase 4. T030 test authorable in parallel with T031. T032 blocks on T031 (renderer contract) + T019 (gate helper). T033 depends on T032.
- **Polish (Phase 6)**: Depends on Phases 3 + 5 completing. Grep gates (T034–T037) parallel. Docs (T038, T039) parallel. Memory (T040, T041, T042) parallel. Quality gates (T043, T044) parallel → T045 sequential → T046 sequential.

### User story dependencies

- **US1 (P1)**: MVP — delivers admin gate + persistence + observability. Can be demoed via unit test / mu-plugin without visiting frontend.
- **US2 (P2)**: Depends on US1's `AbstractEmbedTransport` base + filter enumeration. Third-party extensibility layer.
- **US3 (P1)**: Depends on US1's `is_enabled_for_server()` gate helper. Frontend + F015 cascade + shortcode. Independent of US2.

### Within-story ordering

- Tests written FIRST (TDD per Constitution §II + AGENTS.md). Each test task MUST fail against pre-implementation code and pass after its corresponding implementation lands.
- Abstract base (T016) + all methods (T017–T020) before concrete transports (T021–T023) because concretes `extends` the abstract.
- Tab UI (T024) before save handler (T025) before observability wiring (T026) before Registry addition (T027).

### Parallel opportunities

- **T004 + T005 + T006 + T007** — 4 different new files in `includes/Database/ServerEmbedTransports/`; parallel.
- **T010 + T011 + T012** — different files (phpunit.xml.dist, phpunit.yml, mkdir); parallel.
- **T013 + T014 + T015 + T029 + T030** — 5 test files in different locations; parallel-authorable in one commit.
- **T021 + T022 + T023** — 3 concrete transport classes in different files; parallel.
- **T034 + T035 + T036 + T037** — 4 grep-gate commands, no cross-dependency; parallel.
- **T038 + T039** — different documentation files; parallel.
- **T040 + T041 + T042** — different files/systems; parallel.
- **T043 + T044** — PHPCS + PHPStan independent quality gates; parallel.

### Cross-cut

- **SEC-037-001** (LOW security finding) → folded into **T015** (test) + **T025** (implementation: server-scoped nonce action).
- **SEC-037-002** (LOW security finding) → folded into **T013** (test) + **T018** (implementation: `(int)` coercion in comparator).
- **SEC-037-003** (INFO security finding) → folded into **T015** (test) + **T025** (implementation: absent-checkbox = OFF per HTML form convention).
- **SEC-037-004** (INFO security finding) → folded into **T031** (dedicated `EmbedBlockRenderer.contract.md`).
- **SEC-037-005** (INFO security finding) → folded into **T038** (`$user_id = 0` docs expansion).
- **SEC-037-006** (INFO security finding) → folded into **T039** (Phase 2 block-editor gate-cascade note).
- **DEV5 4th-consumer escalation** — flagged in plan Constitution Check IV; NOT a task here; defer decision to post-implement `/speckit-analyze` gate (matches F015 precedent for mid-flight governance decisions).
- **F034 comparator retrofit (from SEC-037-002)** — flagged in T042 as tech debt candidate for a follow-up PR; NOT in F037 scope.

---

## Parallel Example: User Story 1 concrete transport fan-out

After T016–T020 (abstract base) land:

```bash
# T021 + T022 + T023 all touch different files — safe to fan out.
Task: T021 [US1] Create includes/Embeds/NpmEmbedTransport.php
Task: T022 [US1] Create includes/Embeds/ClientEmbedTransport.php
Task: T023 [US1] Create includes/Embeds/AiConnectorEmbedTransport.php
```

---

## Implementation Strategy

### MVP First (User Story 1 only)

1. Complete Phase 1 + Phase 2 (T001–T012).
2. Complete Phase 3 US1 (T013–T028).
3. **STOP and VALIDATE**: run `--testsuite=embeds`; verify master + transport toggles persist + observability actions fire.
4. Shippable MVP if US2/US3 defer — companion plugins can register their own transports (US2) and consume the gate via `is_enabled_for_server()` without waiting for the base plugin's shortcode.

### Recommended: Ship all three stories together

US2 + US3 flow naturally from US1. Shipping just US1 (admin gate + persistence) without US3 (frontend cascade) leaves the feature half-useful — you can toggle but nothing consumes the toggle in the base plugin. Recommend all 6 phases as one PR.

### Parallel team strategy

With multiple developers post-Phase-2:
- Developer A: T013 + T014 + T015 (US1 tests) + T016–T020 (base class + methods)
- Developer B: T021 + T022 + T023 (3 concrete transports — pure parallel)
- Developer C: T024 + T025 + T026 + T027 + T028 (tab UI + save + Registry + Main wiring) — waits for A's T019 (gate helper).
- Developer D: T029 (US2 test) + T030 + T031 + T032 + T033 (US3) — waits for A's T019.
- Developer E: T038 + T039 + T040 + T041 + T042 (docs + changelog + memory) — parallel with anyone.

---

## Notes

- [P] tasks = different files OR distinct-region back-to-back writes. Safe to fan out.
- [US1] / [US2] / [US3] label maps task to spec.md user story for traceability.
- Every task cites an exact file path. No vague tasks.
- Tests written BEFORE implementation. TDD enforced.
- Commit after each task or per-phase logical group.
- SEC-037-001 (server-scoped nonce) + SEC-037-002 (comparator coercion) are plan-phase security-review preservation invariants — DO NOT skip T018 and T025; per DEC-F025-TASKS-REVIEW-PRESERVATION-INVARIANT-AND-COVERAGE-MATRIX pattern.
- Grep gates SC-005, SC-006, A1, B21 have 4 dedicated tasks (T034–T037) with exact commands spelled out per B26 defense.
- Feature-number discrepancy: brief at `docs/planings-tasks/037-shortcode-block-embeds.md`; spec dir `036-shortcode-block-embeds` per next-sequential numbering after F035. No functional impact; resolve at merge if desired.

---

## SEC-037-001..006 + Governance Coverage Matrix

Per DEC-F025-TASKS-REVIEW-PRESERVATION-INVARIANT-AND-COVERAGE-MATRIX, every plan-review finding + governance concern maps to a remediation task ID:

| Finding | Severity | Owner Task(s) | Verification |
|---|---|---|---|
| SEC-037-001 | LOW | ~~T025 server-scoped nonce~~ **OBSOLETED by Q4 pivot** — the REST URL `{server_id}` path parameter makes cross-server bypass structurally impossible; server-scoped nonce was the admin-form-era mitigation | Structural, not testable via nonce-replay |
| SEC-037-002 | LOW | T013 (test), T018 (impl: `(int)` coercion + optional `_doing_it_wrong`) | `test_get_priority_type_coercion` |
| SEC-037-003 | INFO | ~~T025 absent-checkbox parse rule~~ **OBSOLETED by Q4 pivot** — REST body is structured JSON with explicit `master: bool` + `transports: {key: bool}` fields; missing fields rejected by `register_rest_route` `args` schema, not treated as OFF | Structural, not testable via checkbox absence |
| SEC-037-004 | INFO | T031 (create `contracts/EmbedBlockRenderer.contract.md`) | Manual doc review |
| SEC-037-005 | INFO | T038 (docs expansion in contract + data-model + quickstart) | Manual doc review |
| SEC-037-006 | INFO | T039 (Phase 2 block-editor scope note in spec Assumptions) | Manual doc review |
| DEV5 4th-consumer escalation | **RETRACTED** | F037 no longer uses DEV5 post-Q4 pivot; consumer count drops back to 3 (below D13 threshold) | Documented in Clarifications Q4 + plan §Constitution Check IV |
| F034 comparator retrofit (from SEC-037-002 pattern) | Tech Debt | Deferred (follow-up PR, not in F037 scope) | Flagged in T042 |
| B1 regex bugfix | CRITICAL runtime | Widened `/\A[a-z0-9-]{1,64}\z/` → `/\A[a-z0-9_-]{1,64}\z/` in 5 files | `ConcreteTransportsTest::test_transport_key_matches_regex` |
| **Q4 pivot** | **DESIGN** | 7 PIVOT-* tasks below (all DONE) — full React + REST | React app renders + saves via REST endpoint |

---

## Q4 Pivot Tasks (2026-07-27 — Option B — Full React with REST)

Per Q4 clarification + D37 codification. Supersedes admin-form-based T024/T025/T026 partial (the observability action logic + write logic moved from `Settings::handle_save_embeds()` to `EmbedsController::save()`, not deleted). Same URL pattern as F017 Abilities + F020 Tools: `/acrossai-mcp-manager/v1/servers/{server_id}/{resource}`.

- [x] **PIVOT-1** Delete admin-form scaffolding: remove `save_embeds` from `Settings::handle_actions()` dispatcher + delete `Settings::handle_save_embeds()` method entirely; replace with docblock pointer to `EmbedsController`.
- [x] **PIVOT-2** Rewrite `EmbedsTab::render_body()`: replace form HTML + inline vanilla JS with `<div id="acrossai-mcp-embeds-root"></div>` React mount + `<noscript>` fallback showing read-only current state.
- [x] **PIVOT-3** Create `includes/REST/EmbedsController.php`: singleton with `private __construct()` (S6); `register_routes()` registers GET+POST at `/acrossai-mcp-manager/v1/servers/{server_id}/embeds` matching F017 + F020 URL shape verbatim; `permission_callback` verifies `manage_options` (S2); `save()` mirrors the removed `handle_save_embeds()` diff+write+observability logic; `read()` + `assemble_state()` shape response so React first-renders without a second round-trip.
- [x] **PIVOT-4** Wire `EmbedsController::register_routes` on `rest_api_init` in `Main::define_admin_hooks()` per A1.
- [x] **PIVOT-5** Create `src/js/embeds.js`: React app using ONLY sanctioned packages per D-DATAVIEWS + D37 — `@wordpress/element`, `@wordpress/api-fetch` with **nonce middleware only** per B25, `@wordpress/i18n`, `@wordpress/components`. Reads initial state from `window.acrossaiMcpEmbeds` bootstrap.
- [x] **PIVOT-6** Create `src/scss/embeds.scss` + add `js/embeds` webpack entry.
- [x] **PIVOT-7** Add `maybe_enqueue_embeds_app()` to `admin/Main.php`: `?tab=embeds` guard, asset manifest read, script + optional CSS enqueue, `wp_localize_script( 'acrossaiMcpEmbeds', ... )` bootstrap. Mirrors `maybe_enqueue_abilities_app()` shape verbatim.

**Deferred to post-merge follow-up** (matches F035 CI-verified pattern):

- **PIVOT-T** Write `tests/phpunit/Embeds/EmbedsControllerTest.php`: permission_callback (403 on cap miss), route registration, GET returns correct shape, POST diffs + writes + fires observability actions matrix (SC-010 4 scenarios), POST no-op emits nothing, invalid server_id → 404. Runs under `embeds` PHPUnit suite (already registered).

**Codebase-wide durable memory proposed:** D37 / DEC-ADMIN-UI-REACT-FIRST — awaiting user approval (proposed via `/speckit-memory-md-capture` shape below).

---

## Post-Q4 Pivot Tasks (Retrospective — record of iterations post-`/speckit-implement`)

Three subsequent architectural pivots landed after the Q4 React+REST pivot. This section records them as retrospective task groups so tasks.md matches the shipped codebase. Not TDD tasks (implementation preceded — these are the post-hoc traceability entries).

### Pivot A — Per-DTO gate redesign

- [x] **PIVOT-A1** Widen `AbstractEmbedTransport::is_enabled_for_server()` signature from `(int, string)` to `(int, string, string)` — third arg `$dto_slug`. Update all callers (frontend renderer + tests).
- [x] **PIVOT-A2** Add `entry_enables_slug( $entry, string $dto_slug, bool $is_single ): bool` static helper — uniform gate for both runtime check + REST diff.
- [x] **PIVOT-A3** Add instance methods `get_dtos(): array` (subclass returns F035 DTOs it gates) + `is_single_item(): bool` (single-item shape flag) to `AbstractEmbedTransport`. Override in the 3 built-ins to delegate to `ConnectionMethodRegistry`.
- [x] **PIVOT-A4** Widen `acrossai_mcp_embed_transport_toggled` observability action signature from 4 args to 5 (insert `$dto_slug` as 3rd arg). BREAKING CHANGE — README `= Unreleased =` changelog entry.
- [x] **PIVOT-A5** Widen R2 memoization cache key from `"{server_id}:{transport_key}"` to `"{server_id}:{transport_key}:{dto_slug}"`. Update `flush_cache()` docstring.
- [x] **PIVOT-A6** Frontend renderer (`EmbedBlockRenderer::render_shortcode`) iterates DTOs from `ConnectionMethodRegistry` and calls the 3-arg `is_enabled_for_server()` per-DTO — DTOs whose gate fails are DROPPED from output.

### Pivot B — Meta-table storage refactor

- [x] **PIVOT-B1** Create new BerlinDB module `includes/Database/MCPServerMeta/{Schema,Table,Row,Query}.php` — WP-canonical shape (`meta_id`, `server_id`, `meta_key`, `meta_value`). Version `1.0.0` initial.
- [x] **PIVOT-B2** Add `AbstractEmbedTransport` constants `META_KEY_MASTER = '_embeds_enabled'` + `META_KEY_ITEMS = '_embeds_clients'`. Add instance method `get_storage_key(): string` (default `$this->get_transport_key()`; overridable). Add static helpers `get_items_for_server( int ): array`, `save_items_for_server( int, array ): void`, `meta_for( string ): array{storage_key, is_single}`.
- [x] **PIVOT-B3** Bump `MCPServer\Table::$version 1.1.3 → 1.1.4`. Add `upgrade_to_1_1_4()` callback: DROP COLUMN `embeds_enabled` + DROP TABLE `wp_acrossai_mcp_server_embed_transports` + `delete_option( 'acrossai_mcp_server_embed_transports_db_version' )`. Idempotent per `INFORMATION_SCHEMA` existence checks.
- [x] **PIVOT-B4** DELETE `includes/Database/ServerEmbedTransports/{Schema,Table,Row,Query}.php` — module retired. Update `Main::load_hooks()` + `Main::reconcile_database_schemas()` — remove `ServerEmbedTransports\Table` boot + reconciliation; add `MCPServerMeta\Table` boot + reconciliation.
- [x] **PIVOT-B5** Rewrite `AbstractEmbedTransport::is_enabled_for_server()` internals — read `META_KEY_MASTER` from meta table, decode `META_KEY_ITEMS` JSON blob, delegate to `entry_enables_slug()`. Cache-key gains 3rd component (matches PIVOT-A5).
- [x] **PIVOT-B6** Rewrite `AbstractEmbedTransport::garbage_collect_orphans()` — iterate every server's `_embeds_clients` row, decode, drop unknown category-keys, save back. Idempotent.
- [x] **PIVOT-B7** Rewrite `EmbedsController::save()` + `EmbedsController::assemble_state()` (later folded into `EmbedsTab` per Pivot C) — write to meta table via `save_items_for_server`; read via `get_items_for_server`; diff for observability via `entry_enables_slug`.
- [x] **PIVOT-B8** Bump `MCPServerMeta\Table::$version 1.0.0 → 1.0.1`. Add `upgrade_to_1_0_1()` callback: DELETE meta rows with `meta_key LIKE '_embed_dto:%'` (cleans up the intermediate per-DTO-row iteration before Pivot B consolidated to `_embeds_clients` JSON).

### Pivot C — `AbstractReactMountServerTab` extraction

- [x] **PIVOT-C1** Create new intermediate base class `admin/Partials/ServerTabs/AbstractReactMountServerTab.php` (~400 lines including inline usage guide). Owns four subsystems: asset enqueue (`enqueue_assets_if_active`), REST GET/POST controller (`register_rest_routes` + `rest_read` + `rest_save` + `rest_permission_callback`), state contract (abstract `get_state_for_server` + `set_state_for_server`), self-registration (`register()` — idempotent, wires 3 hooks). Class-level docblock includes copy-pastable third-party consumer example.
- [x] **PIVOT-C2** Reparent `EmbedsTab` — extend `AbstractReactMountServerTab` instead of `AbstractServerTab` directly. Fold `EmbedsController::save()` + `EmbedsController::assemble_state()` bodies into `EmbedsTab::set_state_for_server()` + `EmbedsTab::get_state_for_server()`. Add declarative getters (`get_asset_handle`, `get_asset_manifest_path`, `get_asset_script_url`, `get_asset_style_url`, `get_localize_object_name`, `get_rest_route_path`, `get_save_request_args`). Override `summary_rows_from_state()` for domain-shaped noscript view (master + per-transport enabled counts).
- [x] **PIVOT-C3** DELETE `includes/REST/EmbedsController.php` — its logic is fully absorbed into `EmbedsTab` via the base class. Update `includes/Main.php` — replace the `EmbedsController::register_routes` action wiring with a single `EmbedsTab::register()` call.
- [x] **PIVOT-C4** DELETE `admin/Main.php::maybe_enqueue_embeds_app()` (~92 lines) + its call site — asset enqueue is now handled by `AbstractReactMountServerTab::enqueue_assets_if_active()` self-registered via `EmbedsTab::register()`.
- [x] **PIVOT-C5** Fix duplicate-slug `_doing_it_wrong` notice — `AbstractReactMountServerTab::register()` filter callback dedups by slug (skips when the tab is already seeded by `Registry::all_tabs()` — prevents notice for built-in tabs while still working for third-party contributions).
- [x] **PIVOT-C6** Add new contract file `specs/036-shortcode-block-embeds/contracts/AbstractReactMountServerTab.contract.md` covering the extension surface, method contracts, and third-party consumer pattern (with `class_exists()` guard).

### Retroactive gap tasks

- [x] **T-CLEANUP** (retroactive for FR-017 coverage gap identified in `/speckit-analyze`): Wire `Main::cleanup_embed_transports_on_server_delete( $server_id )` on the `acrossai_mcp_server_deleted` action. The handler calls `MCPServerMeta\Query::delete_by_server_id( $server_id )` (cascades every meta row for the server, not just F037's — the meta table is generic per-server storage; server deletion should orphan zero rows). Handled organically during Pivot B but not tracked in original tasks.md.

### Rescoped pending tasks (need re-execution against shipped API)

The following pending tasks reference the pre-Pivot API and MUST be rewritten before implementation:

- [ ] **T015-R** (was T015 — EmbedsTab save handler tests): Rescope to test `AbstractReactMountServerTab::rest_save()` + `EmbedsTab::set_state_for_server()`. Test the 5-arg `acrossai_mcp_embed_transport_toggled` action per Pivot A. Cross-server bypass now structural (URL path parameter), not nonce-based — the SEC-037-001 test scenario is obsolete.
- [ ] **T029-R** (was T029 — third-party transport E2E): Add coverage for the new methods (`get_storage_key`, `is_single_item`, `get_dtos`). Third-party is now expected to override 5 methods, not 3.
- [ ] **T030-R** (was T030 — shortcode cascade): Rescope from 12-combination whole-category matrix to representative-per-DTO sampling per SC-004 (revised). Assert per-DTO drop behavior when a DTO's gate fails.
- [ ] **T038** (SEC-037-005 $user_id docs): Still pending. Update quickstart.md + contract file with `$user_id = 0` semantics for the 5-arg action signature.
- [ ] **T039** (SEC-037-006 Phase 2 block-editor note): Still pending. Add note in spec §Assumptions.
- [ ] **T042** (Memory hygiene — F034 comparator retrofit tech debt): Still pending. Consider if D37 (React-first) + D-ABSTRACT-REACT-MOUNT-TAB (new base class) warrant durable memory capture.
- [ ] **T045** (Full PHPUnit suite run): Still pending. Now includes `AbstractReactMountServerTab` REST route tests + PIVOT-B4 module deletion sanity check.
- [ ] **T046** (Manual verification): Still pending — needs re-scoping to per-DTO toggles + React app hydration + noscript fallback rendering per shipped state.
- [ ] **PIVOT-T** (was PIVOT-T deferred — controller tests): OBSOLETED by PIVOT-C3 (controller deleted). Rescope to test the base class's `rest_read` + `rest_save` methods with a fake subclass fixture.
