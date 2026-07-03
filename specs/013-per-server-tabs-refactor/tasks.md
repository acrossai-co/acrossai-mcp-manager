---
description: "Task list for Feature 013 — Port per-server-edit tabs to a common per-tab class hierarchy + Public Renderer layer for third-party (BuddyBoss/WooCommerce) embedding"
---

# Tasks: Port per-server-edit tabs to a common per-tab class hierarchy + Public Renderer layer (Feature 013)

**Input**: Design documents from `specs/013-per-server-tabs-refactor/`
**Prerequisites**: `spec.md` (274 lines + 5 Clarifications), `plan.md` (168 lines), `memory-synthesis.md` (887 words), `security-constraints.md` (97 lines), `architecture-violations.md` (104 lines), `docs/security-reviews/2026-07-03-013-per-server-tabs-refactor-plan.md`, `docs/planings-tasks/013-per-server-tabs-refactor.md` (826 lines)

**Tests**: The plan mandates PHPUnit coverage — `RegistryTest`, `AbstractServerTabTest`, `AbstractClientRendererTest`, `PublicApiTest`. Security review SEC-013-001..004 fold into `PublicApiTest` cases. Manual smoke tests deferred to reviewer per `post-merge-verification.txt` convention.

**Organization**: Tasks are grouped by user story per spec.md (US1..US5). US2/US3/US4 are interleaved because their invariants are delivered in the same Renderer subsystem (US2 = the layer; US3 = the F012 gate inside two Blocks; US4 = the REST endpoint + button gating).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks)
- **[Story]**: Which user story this task belongs to (`US1`, `US2`, `US3`, `US4`, `US5`); Setup/Foundational/Polish tasks have no story label

## Path Conventions

- Plugin root: `/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/plugins/acrossai-mcp-manager/`
- All paths below are relative to the plugin root unless otherwise noted
- PHP source under `admin/`, `includes/`, `public/`, `acrossai-mcp-manager.php`
- PHPUnit tests under `tests/phpunit/`
- Docs under `docs/`

## Constitution §VII per-task gate (applies to EVERY task below)

Before marking any task complete, run:
- `vendor/bin/phpcs` — zero errors, zero warnings on touched files
- `vendor/bin/phpstan analyse --level=8` — zero errors on touched files
- Any grep gate explicitly named in the task description

A task is not "done" until its DoD line is green.

---

## Phase 1: Setup (pre-flight snapshot + test harness sanity)

**Purpose**: Capture reference state + verify the PHPUnit test harness covers the new subdirs.

- [x] T001 [P] Capture pre-flight grep snapshots to `specs/013-per-server-tabs-refactor/pre-flight-reference-plugin.txt`:
  ```
  grep -rEn "render_general_tab|render_access_control_tab|render_claude_connector_tab|render_tokens_tab" --include='*.php' admin/ > specs/013-per-server-tabs-refactor/pre-flight-4-old-methods.txt
  grep -rn 'ACROSSAI_MCP_MANAGER\\\\' --include='*.php' admin/ includes/ public/ acrossai-mcp-manager.php > specs/013-per-server-tabs-refactor/pre-flight-legacy-namespace.txt
  wc -l /Users/raftaar1191/local-sites/wordpress-ai/app/public/wp-content/plugins/acrossai-mcp-manager/src/Admin/Settings.php > specs/013-per-server-tabs-refactor/pre-flight-reference-plugin.txt
  ```
  **DoD**: 3 snapshot files exist; the 4-old-methods grep returns 4 hits (baseline); the legacy-namespace grep returns 0 hits (baseline confirmation).

- [x] T002 [P] Verify `tests/phpunit/Admin/ServerTabs/` + `tests/phpunit/Public/Renderers/` subdirectories are covered by the `admin` testsuite in `phpunit.xml.dist`. F012 added the `admin` testsuite pointing at `tests/phpunit/Admin/`; extend that entry to also include `tests/phpunit/Public/Renderers/` (or add a new `renderers` testsuite). **DoD**: create a throwaway `tests/phpunit/Admin/ServerTabs/BootstrapProbe.php` + `tests/phpunit/Public/Renderers/BootstrapProbe.php` each asserting `true`; run `vendor/bin/phpunit --testsuite admin --bootstrap tests/bootstrap-wp.php`; delete the probes after verification.

- [x] T003 [P] Verify the reference plugin's `src/Admin/Settings.php` line ranges cited in spec are stable. Grep for the 11 render method names at their expected line ranges. If the reference plugin has been edited since the spec was drafted, log the drift in `specs/013-per-server-tabs-refactor/pre-flight-reference-plugin.txt` for the porter to consult. **DoD**: the 11 render method names appear at ranges within ±5 lines of the spec citations; drift log written.

---

## Phase 2: Foundational (AbstractServerTab + Registry + PHPUnit harness — blocking prerequisite for all user stories)

**Purpose**: Scaffold the class hierarchy base + dispatch + tests so US5/US1/US2 can hang off it.

- [x] T004 Full write: `admin/Partials/ServerTabs/AbstractServerTab.php` (NEW). Namespace `AcrossAI_MCP_Manager\Admin\Partials\ServerTabs`. Abstract class with abstract `slug(): string`, `label(): string`, `render_body( array $server ): void`; concrete `visible_for( array $server ): bool { return true; }`; final `render( array $server ): void { $this->render_body( $server ); }`; protected shared helpers `open_form( array $server, string $action ): void`, `close_form( string $submit_label ): void`, `nonce_field( array $server ): void`, `json_config_block( array $server, string $client_slug, array $config ): void`, `passwords_notice(): void`, `server_edit_url( array $server, string $tab ): string`, `client_label_pair( string $client_name, string $vendor ): void`. Nonce action MUST be `'acrossai_mcp_manager_server_' . (int) $server['id']`. `server_edit_url()` MUST use `esc_url( add_query_arg(...) )` around `admin_url()` per S5/B6. All `printf` calls use ONE placeholder style per B16. **DoD**: `php -l` clean; PHPStan L8 zero errors; PHPCS zero errors, zero warnings.

- [x] T005 Full write: `admin/Partials/ServerTabs/Registry.php` (NEW). Namespace `AcrossAI_MCP_Manager\Admin\Partials\ServerTabs`. Final class, protected static `$instance = null;` → `public static function instance(): self` → `private function __construct() {}` — matching F012 SettingsMenu singleton member ordering. Public methods: `all_tabs(): array` (returns ORDERED array of AbstractServerTab subclass instances — empty until T012/T014 register concrete tabs; use `apply_filters( 'acrossai_mcp_server_tabs', [] )` optionally for extensibility, or hardcode the 11-class registry as an internal method), `visible_tabs( array $server ): array` (filters by `->visible_for( $server )`), `render( string $tab_slug, array $server ): void` (dispatches; falls back to `OverviewTab` on unknown slug — cast fallback as a comment; the class doesn't exist until T012 so leave a `// TODO(T012): OverviewTab fallback` comment). **DoD**: `php -l` clean; PHPStan L8 zero errors; PHPCS zero errors, zero warnings.

- [x] T006 [P] Create NEW file: `tests/phpunit/Admin/ServerTabs/RegistryTest.php`. Extend `WP_UnitTestCase`. Docblock cites `BUGS.md B9` (use `#[DataProvider]` attribute). Test methods:
  1. `test_all_tabs_returns_empty_array_before_registration`: assert `Registry::instance()->all_tabs()` returns `[]` (valid state at T005; concrete tabs registered later).
  2. `test_render_unknown_slug_falls_back_gracefully`: assert `Registry::instance()->render( 'unknown-slug', [ 'id' => 1 ] )` does NOT fatal (may render nothing until fallback exists).
  Both tests will be expanded in T029 (post-tab-registration) and T039 (post-11-tab-registration).
  **DoD**: PHPStan L8 + PHPCS green; `vendor/bin/phpunit --testsuite admin --bootstrap tests/bootstrap-wp.php --filter RegistryTest` zero failures.

- [x] T007 [P] Create NEW file: `tests/phpunit/Admin/ServerTabs/AbstractServerTabTest.php`. Docblock cites B9. Test methods verify `open_form()` emits `<form method="post"` + `action="..."`; `nonce_field()` emits `<input type="hidden" name="_wpnonce" value="..."` with action name `'acrossai_mcp_manager_server_' . (int) $server['id']`; `json_config_block()` emits `<pre>` with escaped JSON; `passwords_notice()` emits an accessible notice. Use output buffer + `assertStringContainsString`. **DoD**: PHPStan L8 + PHPCS green; `vendor/bin/phpunit ... --filter AbstractServerTabTest` zero failures.

**Checkpoint**: Foundational scaffolding complete. Empty Registry passes tests. AbstractServerTab helpers verified via output-buffer assertions.

---

## Phase 3: User Story 5 — Existing 4-tab UI regresses to zero UI change (Priority: P2 — shape validation checkpoint)

**Goal**: Refactor the 4 existing tabs (General, Tokens, Access Control, Claude Connector) into per-tab classes with ZERO visible change to their operator-facing UI. Delete the 4 old `render_*_tab` methods from `Settings.php`. Rewrite `Settings.php::render_edit_page()` to dispatch via Registry.

**Independent Test**: On live WP, screenshot each of the 4 existing tabs before + after Phase 3 → visually diff. PHPUnit — capture output hash for each tab pre-Phase-3, compare to post-Phase-3 hash; any diff other than whitespace requires review.

### Implementation for User Story 5

- [x] T008 [P] [US5] Create NEW file: `admin/Partials/ServerTabs/OverviewTab.php`. Namespace `AcrossAI_MCP_Manager\Admin\Partials\ServerTabs`. Extends `AbstractServerTab`. `slug() = 'overview'`. `label() = __( 'Overview', 'acrossai-mcp-manager' )`. `render_body()` is a MINIMAL SHELL for now — ports the current `Settings.php::render_general_tab()` body verbatim (form open via `$this->open_form( $server, 'save_general' )`, current form fields, form close via `$this->close_form( __( 'Save Server', 'acrossai-mcp-manager' ) )`). Will be enriched to full 147-LOC content in T033. **DoD**: `php -l` + PHPStan L8 + PHPCS green.

- [x] T009 [P] [US5] Create NEW file: `admin/Partials/ServerTabs/TokensTab.php`. `slug() = 'tokens'`. `label() = __( 'Tokens', 'acrossai-mcp-manager' )`. `render_body()` is a THIN DELEGATE to `\AcrossAI_MCP_Manager\Admin\Partials\ApplicationPasswords::render_for_server( (int) $server['id'] )`. No new HTML rendering. `visible_for()` returns `parent::visible_for( $server )` (always true). **DoD**: file exists; no `<form>`, no `wp_nonce_field` in body; PHPStan L8 + PHPCS green.

- [x] T010 [P] [US5] Create NEW file: `admin/Partials/ServerTabs/AccessControlTab.php`. `slug() = 'access-control'`. `label() = __( 'Access Control', 'acrossai-mcp-manager' )`. `render_body()` VERBATIM ports `Settings.php::render_access_control_tab()` body (from CURRENT target Settings.php line ~637 — NOT the reference plugin's 12-LOC stub per Clarifications Q1). Preserve `class_exists( '\WPBoilerplate\AccessControl\AccessControlManager' )` guard per D8. Do NOT extend or improve the guard. **DoD**: guard present verbatim; PHPStan L8 + PHPCS green.

- [x] T011 [P] [US5] Create NEW file: `admin/Partials/ServerTabs/ClaudeConnectorTab.php`. `slug() = 'claude-connector'`. `label() = __( 'Claude Connector', 'acrossai-mcp-manager' )`. `render_body()` is a MINIMAL PORT of the CURRENT `Settings.php::render_claude_connector_tab()` body (~lines 664). Use `$this->open_form()` + `$this->nonce_field()` + `$this->close_form()` — NOT raw `<form>` + `wp_nonce_field`. Does NOT wire `ConnectorAuditLogListTable` yet (that lands in T032). Does NOT delegate to `ClaudeConnectorBlock` yet (that conversion lands in T032 too). This is the shape-validation shell. **DoD**: file exists; no raw `<form method="post">` or `wp_nonce_field(` in body; PHPStan L8 + PHPCS green.

- [x] T012 [US5] Delta edit: `admin/Partials/ServerTabs/Registry.php`. Register the 4 tabs into `all_tabs()` in order: `[ OverviewTab, TokensTab, AccessControlTab, ClaudeConnectorTab ]`. Remove the `// TODO(T012)` comment from T005. Update `render()` fallback to instantiate `OverviewTab` explicitly. **DoD**: `Registry::instance()->all_tabs()` returns 4 instances; `RegistryTest::test_slug_ordering()` (add this new test method here) asserts `[ 'overview', 'tokens', 'access-control', 'claude-connector' ]`; PHPStan L8 + PHPCS green.

- [x] T013 [US5] Delta edit: `admin/Partials/Settings.php`. Delete `render_general_tab()`, `render_tokens_tab()`, `render_access_control_tab()`, `render_claude_connector_tab()` methods. Rewrite the switch dispatch inside `render_edit_page()` — replace with:
  ```php
  \AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\Registry::instance()->render( $tab_slug, $server );
  ```
  (after the wrap + breadcrumb + tab-nav emit). Also update `render_edit_page()` to feed `Registry::instance()->visible_tabs( $server )` to `SettingsRenderer::render_tab_nav()` (do NOT touch `SettingsRenderer` itself). **DoD**: `grep -rEn "render_general_tab|render_access_control_tab|render_claude_connector_tab|render_tokens_tab" --include='*.php' admin/` returns **zero hits**; PHPStan L8 + PHPCS green on modified files (baseline unchanged).

- [x] T014 [US5] Manual smoke test on live WP: activate the plugin; navigate to `?page=acrossai_mcp_manager&action=edit&server=1` (a plugin-registered server). Verify each of the 4 tabs renders **pixel-identically to pre-refactor**. Save each tab's form; verify nonce validates, data persists, redirect to `?tab=<slug>&notice=...` fires exactly as before. Record smoke evidence (screenshots + `wp` command output) in `docs/planings-tasks/013-per-server-tabs-refactor.md` under a new "US5 Smoke Evidence" section. **DoD**: 4 tabs visually identical pre/post; 4 form saves work identically.

**Checkpoint**: US5 (P2) complete. The AbstractServerTab shape is validated with zero UI regression. Ready to port new tabs.

---

## Phase 4: User Story 2 — Third-party plugin embeds a client block with zero duplication (Priority: P1)

**Goal**: Ship the public Renderer layer under `public/Renderers/` (AbstractClientRenderer + 3 Block subclasses + REST endpoint) + Loader wiring + PHPUnit coverage + `docs/integrations/` examples. This phase delivers US2 primarily, but the Renderer bodies also enforce US3 (F012 gate) and US4 (App Password lockdown) — those are validated in Phase 5/6.

**Independent Test**: PHPUnit `PublicApiTest::test_admin_and_external_context_produce_identical_body_markup()` (per SC-002) — call `NpmClientBlock::instance()->render()` twice with different contexts; assert core markup is byte-identical modulo form action URL + nonce.

### Implementation for User Story 2

- [x] T015 [US2] Full write: `public/Renderers/AbstractClientRenderer.php` (NEW). Namespace `AcrossAI_MCP_Manager\Public\Renderers`. Docblock cites `@since 0.0.6 @experimental May change without notice before 1.0.0` per Clarifications Q3 + FR-016a. Singleton (protected static `$instance` → `public static function instance(): self` → `private __construct()`). Abstract methods: `slug(): string`, `render_body( array $server, array $context ): void`. Final `render( int $server_id, array $context = [] ): void` — resolves context, cap-checks, loads `$server` via `MCPServerQuery::instance()->get_item( $server_id )`, calls `render_missing_server_notice()` on null, else delegates to `render_body`. `resolve_context()` merges defaults + applies filter — **cast filter return to `(array)` before `wp_parse_args()`** per SEC-013-003. Protected helpers: `passwords_generate_button()` (renders disabled if `$context['user_id'] !== get_current_user_id()` per FR-024), `config_json_pre_block()`, `copy_config_button()`, `render_missing_server_notice()`, `render_feature_disabled_notice( string $feature_label, string $enable_link_text, string $explanation ): void`. All `printf` calls use ONE placeholder style per B16 — see planning doc's code sample for the exact `render_feature_disabled_notice()` shape. Nonce action derivation: `'acrossai_mcp_render_' . $this->slug() . '_' . $server_id . '_' . $context['context']` per FR-022 (context slug bound). **DoD**: `php -l` + PHPStan L8 + PHPCS green.

- [x] T016 [US2] Full write: `public/Renderers/NpmClientBlock.php` (NEW). Namespace `AcrossAI_MCP_Manager\Public\Renderers`. Docblock cites `@since 0.0.6 @experimental` + `See DEC-CLIENT-RENDERER-PUBLIC-API`. Singleton. Extends `AbstractClientRenderer`. `slug() = 'npm'`. `render_body( array $server, array $context ): void`:
  ```php
  $this->render_section_heading( __( 'npm / npx CLI', 'acrossai-mcp-manager' ) );
  $enabled = (bool) get_option( 'acrossai_mcp_npm_login_enabled', false );
  if ( ! $enabled ) {
      $this->render_feature_disabled_notice(
          __( 'npm / npx CLI', 'acrossai-mcp-manager' ),
          __( 'enable CLI Connections in Settings', 'acrossai-mcp-manager' ),
          __( 'Enabling this feature allows terminal users to connect the AcrossAI MCP Manager CLI tool to this WordPress site using the npx command. Users sign in through WordPress and approve access in the browser, then the CLI receives an Application Password automatically so no JSON files need to be configured by hand. Only enable this if you intend to use the CLI for local development or trusted environments.', 'acrossai-mcp-manager' )
      );
  } else {
      // Full config UI — port from reference lines 1258-1351 + adapt to F011 native shape per Q1
      $this->passwords_generate_button( $server, $context );
      // ... config file row + JSON block via $this->config_json_pre_block() + copy button
  }
  // CLI Connection Log ListTable ALWAYS renders (past events remain visible)
  $this->render_cli_connection_log( $server );  // internal helper that instantiates CliAuthLogListTable
  ```
  Consumes `\AcrossAI_MCP_Manager\Public\Partials\FrontendAuth::get_base_url()` for the CLI auth URL. **DoD**: `php -l` + PHPStan L8 + PHPCS green; F012 option name grep returns exactly ONE hit in this file (`grep -rn 'acrossai_mcp_npm_login_enabled' public/Renderers/NpmClientBlock.php`).

- [x] T017 [US2] Full write: `public/Renderers/MCPClientsBlock.php` (NEW). Namespace `AcrossAI_MCP_Manager\Public\Renderers`. Docblock cites `@experimental`. Singleton. Extends `AbstractClientRenderer`. `slug() = 'clients'`. `render_body( array $server, array $context ): void`:
  - Read `$context['sub_client']` (falls back to first client on invalid/absent per FR-013a).
  - Iterate over `apply_filters( 'acrossai_mcp_client_classes', [ ClaudeDesktopClient::class, ClaudeCodeClient::class, VSCodeClient::class, GitHubCopilotClient::class, CodexClient::class, CursorClient::class, CustomClient::class, ... ] )` per FR-016b.
  - For each FQN in the filtered list, validate: `class_exists( $fqn ) && is_subclass_of( $fqn, \AcrossAI_MCP_Manager\Includes\MCPClients\AbstractMCPClient::class )` — silently skip invalid FQNs per FR-016b + SEC-013-008.
  - Render sub-nav as list of buttons/links pointing at `add_query_arg( 'client', $client->get_slug(), $context['submit_target_url'] )` (URL routing per Clarifications Q2).
  - Dispatch to the selected client's per-client render helper (private method inside this class, ported from reference plugin lines 1409-1489).
  - **NOT gated by any F012 toggle** (FR-019). Grep for `acrossai_mcp_npm_login_enabled` or `acrossai_mcp_claude_connectors_enabled` in this file MUST return zero.
  **DoD**: F012 option grep on this file returns 0 hits; PHPStan L8 + PHPCS green.

- [x] T018 [US2] Full write: `public/Renderers/ClaudeConnectorBlock.php` (NEW). Namespace + singleton + `@experimental` docblock. Extends `AbstractClientRenderer`. `slug() = 'claude-connector'`. `render_body()`:
  ```php
  $this->render_section_heading( __( 'Claude Connector', 'acrossai-mcp-manager' ) );
  $enabled = (bool) get_option( 'acrossai_mcp_claude_connectors_enabled', false );
  if ( ! $enabled ) {
      $this->render_feature_disabled_notice(
          __( 'Claude Connector', 'acrossai-mcp-manager' ),
          __( 'enable direct Claude Connectors mode in Settings', 'acrossai-mcp-manager' ),
          __( 'Enabling this feature allows this MCP server to be added directly to Claude Desktop or Claude Code as a native connector. The plugin serves the OAuth authorization-server metadata, authorize URL, and token endpoint required by Claude. Only enable this if you intend to use this server as a Claude connector target.', 'acrossai-mcp-manager' )
      );
  } else {
      // Full config form — port from reference lines 1498-1698 + adapt to F011 native shape per Q1
      // Uses $this->open_render_form(), $this->nonce_field()-equivalent for context-bound nonces
      // Includes client_id / client_secret / redirect_uri fields
      $this->render_claude_connector_fields( $server, $context );
  }
  // Connector audit log ALWAYS renders (past events remain visible)
  $this->render_connector_audit_log( $server );  // instantiates ConnectorAuditLogListTable
  ```
  **DoD**: `php -l` + PHPStan L8 + PHPCS green; `acrossai_mcp_claude_connectors_enabled` grep returns exactly ONE hit in this file.

- [x] T019 [US2] Port `admin/Partials/CliAuthLogListTable.php` (NEW) from reference plugin at `wordpress-ai/.../src/Admin/CliAuthLogListTable.php`. Adaptation per FR-008:
  1. Namespace uppercase→PascalCase: `ACROSSAI_MCP_MANAGER\Admin` → `AcrossAI_MCP_Manager\Admin\Partials`.
  2. Replace `use ACROSSAI_MCP_MANAGER\Database\...` with `use AcrossAI_MCP_Manager\Includes\Database\CliAuthLog\Query;`.
  3. Query calls use F011 BerlinDB API (`Query::instance()->query( [...] )`).
  4. Add `defined( 'ABSPATH' ) || exit;`.
  5. File docblock CITES `DEC-ADMIN-SURFACE-PRUNE-CLI-AUTH-LOG` explaining per-server-tab reintroduction is on-pattern (per FR-008 + memory-synthesis load-bearing item).
  6. `extends \WP_List_Table` with leading `\` per B15.
  **DoD**: legacy-namespace grep on this file = 0; PHPStan L8 + PHPCS green; file docblock contains string `DEC-ADMIN-SURFACE-PRUNE-CLI-AUTH-LOG`.

- [x] T020 [US2] Port `admin/Partials/ConnectorAuditLogListTable.php` (NEW) from reference plugin. Adaptation per FR-009: namespace PascalCase, `use AcrossAI_MCP_Manager\Includes\Database\OAuthAudit\Query;`, ABSPATH guard, leading-`\` `extends \WP_List_Table`. This is a new file to target (never existed in target). **DoD**: legacy-namespace grep = 0; PHPStan L8 + PHPCS green.

- [x] T021 [US2] Full write: `includes/REST/ClientRendererController.php` (NEW). Namespace `AcrossAI_MCP_Manager\Includes\REST`. Docblock cites `@experimental` + `See DEC-CLIENT-RENDERER-PUBLIC-API` + `SEC-013-002` (App Password lockdown). Singleton. `register_rest_routes()` registers POST `/wp-json/acrossai-mcp-manager/v1/generate-app-password` with explicit `permission_callback` per FR-023 + S2:
  ```php
  'permission_callback' => function ( \WP_REST_Request $request ) {
      if ( ! is_user_logged_in() ) { return new \WP_Error( 'rest_forbidden', '', [ 'status' => 403 ] ); }
      $body_user_id = absint( $request->get_param( 'user_id' ) );
      if ( 0 === $body_user_id || $body_user_id !== get_current_user_id() ) {
          return new \WP_Error( 'rest_forbidden', '', [ 'status' => 403 ] );  // SEC-013-002
      }
      $server_id   = absint( $request->get_param( 'server_id' ) );
      $client_slug = sanitize_key( $request->get_param( 'client_slug' ) );
      $context     = sanitize_key( $request->get_param( 'context' ) );
      $nonce       = $request->get_header( 'X-WP-Nonce' );
      $expected    = 'acrossai_mcp_render_' . $client_slug . '_' . $server_id . '_' . $context;
      if ( ! wp_verify_nonce( $nonce, $expected ) ) {
          return new \WP_Error( 'rest_forbidden', '', [ 'status' => 403 ] );  // SEC-013-001
      }
      return true;
  }
  ```
  Callback creates the App Password via `WP_Application_Passwords::create_new_application_password( get_current_user_id(), [ 'name' => ... ] )`. Uses strict `===` comparison; `absint()` before compare; `get_current_user_id()` (not `wp_get_current_user()->ID`) per SEC-013-002 remediation. **DoD**: PHPStan L8 + PHPCS green; permission_callback returns 403 under all 3 mismatch conditions.

- [x] T022 [US2] Delta edit: `includes/Main.php`. Inside `define_public_hooks()`, add Loader wiring for the Renderer public API:
  ```php
  // Feature 013 — Public Renderer layer
  $client_renderer_rest = \AcrossAI_MCP_Manager\Includes\REST\ClientRendererController::instance();
  $this->loader->add_action( 'rest_api_init', $client_renderer_rest, 'register_rest_routes' );
  $this->loader->add_action( 'init', function () {
      add_shortcode( 'acrossai_mcp_npm_block', [ \AcrossAI_MCP_Manager\Public\Renderers\NpmClientBlock::class, 'shortcode' ] );
      add_shortcode( 'acrossai_mcp_clients_block', [ \AcrossAI_MCP_Manager\Public\Renderers\MCPClientsBlock::class, 'shortcode' ] );
      add_shortcode( 'acrossai_mcp_claude_connector_block', [ \AcrossAI_MCP_Manager\Public\Renderers\ClaudeConnectorBlock::class, 'shortcode' ] );
  } );
  $this->loader->add_action( 'acrossai_mcp_render_client_block', /* dispatcher — 3-arg action */, 10, 3 );
  ```
  Each block class adds a static `shortcode( $atts ): string` method that captures output buffer around a `self::instance()->render()` call. Loader-wire the action-hook dispatcher via a small internal method in `Main` that reads `$renderer_slug` and dispatches to the right Block. **DoD**: all 4 hooks wired via Loader (not in class bodies) per A1; PHPStan L8 + PHPCS green on Main.php (baseline unchanged).

- [x] T023 [US2] Create NEW file: `tests/phpunit/Public/Renderers/AbstractClientRendererTest.php`. Docblock cites B9. Test methods: `test_resolve_context_merges_defaults` (asserts defaults applied); `test_resolve_context_applies_filter` (registers a filter modifying `cap`, asserts modification present); `test_resolve_context_casts_non_array_filter_return` (registers filter returning `null` / `false` / `'string'` → asserts no fatal, defaults applied — **SEC-013-003 mitigation test**); `test_render_missing_server_notice_when_invalid_id` (asserts "server not found" markup when `MCPServerQuery::get_item()` returns null); `test_render_silent_no_op_when_cap_fails` (assert empty output when current user lacks context cap). Use `#[DataProvider]` where applicable. **DoD**: 5 test methods green.

- [x] T024 [US2] Create NEW file: `tests/phpunit/Public/Renderers/PublicApiTest.php`. Docblock cites B9. Test methods:
  1. `test_admin_and_external_context_produce_identical_body_markup` — **SC-002 byte-identity test**. Call `NpmClientBlock::instance()->render( 1, [ 'context' => 'admin' ] )` twice with different contexts; assert core markup byte-identical modulo form action URL + nonce.
  2. `test_shortcode_renders_full_block` — assert `do_shortcode( '[acrossai_mcp_npm_block server="1"]' )` returns non-empty when current user has `manage_options`.
  3. `test_action_hook_dispatches_to_correct_renderer` — assert `do_action( 'acrossai_mcp_render_client_block', 'npm', 1, [] )` produces the Npm block; `'unknown'` produces no output (silent no-op per FR-015).
  4. **SEC-013-008** `test_client_classes_filter_silently_skips_invalid_fqn` — register filter appending an invalid FQN + a class that doesn't extend `AbstractMCPClient`; assert MCPClientsBlock renders valid clients + no fatal.
  **DoD**: 4 test methods green; PHPStan L8 + PHPCS green.

- [x] T025 [P] [US2] Create NEW file: `docs/integrations/buddyboss-example.md` per FR-016c. Content per SEC-013-007 checklist: (a) minimal shortcode + `do_action` snippets rendering the 3 blocks in a BuddyBoss profile tab; (b) `apply_filters('acrossai_mcp_client_block_context', ...)` example with `cap='read'` for viewing own config + note that mutating actions would need a stricter cap; (c) security note that "Generate Application Password" button will be disabled unless `$context['user_id'] === get_current_user_id()`; (d) example extending sub-nav via `add_filter('acrossai_mcp_client_classes', ...)` including `class_exists() + is_subclass_of()` validation on the appended FQN. **DoD**: file exists with all 4 sections present.

- [x] T026 [P] [US2] Create NEW file: `docs/integrations/woocommerce-example.md` per FR-016c. Same 4 sections as T025 but adapted for WooCommerce My Account custom tab context. **DoD**: file exists; all 4 sections present.

- [x] T027 [US2] Delta edit: `admin/Partials/ServerTabs/NpmTab.php`. Was a MINIMAL SHELL from T029 (below); no — wait, it doesn't exist yet. **Create NEW file** as a THIN DELEGATE to `NpmClientBlock`:
  ```php
  final class NpmTab extends AbstractServerTab {
      public function slug(): string { return 'npm'; }
      public function label(): string { return __( 'npm', 'acrossai-mcp-manager' ); }
      protected function render_body( array $server ): void {
          $sub_client = isset( $_GET['client'] ) ? sanitize_key( wp_unslash( $_GET['client'] ) ) : '';
          // phpcs:ignore WordPress.Security.NonceVerification.Recommended — sub-nav routing only, no state mutation
          \AcrossAI_MCP_Manager\Public\Renderers\NpmClientBlock::instance()->render(
              (int) $server['id'],
              [
                  'context'           => 'admin',
                  'cap'               => 'manage_options',
                  'submit_target_url' => $this->server_edit_url( $server, 'npm' ),
                  'nonce_action'      => 'acrossai_mcp_manager_server_' . (int) $server['id'],
                  'sub_client'        => $sub_client,
              ]
          );
      }
  }
  ```
  Grep for `<pre>` / `<textarea>` / `Configuration JSON` in this file MUST return 0. **DoD**: PHPStan L8 + PHPCS green; content grep = 0.

- [x] T028 [US2] Delta edit: `admin/Partials/ServerTabs/ClientsTab.php`. Create NEW file as a THIN DELEGATE to `MCPClientsBlock` (same shape as T027 with `slug() = 'clients'`, `label() = __( 'MCP Clients', ... )`, delegates to `MCPClientsBlock::instance()->render()` with `sub_client` from `$_GET['client']` per Clarifications Q2). **DoD**: content grep = 0; PHPStan L8 + PHPCS green.

- [x] T029 [US2] Delta edit `admin/Partials/ServerTabs/ClaudeConnectorTab.php` from T011 shape to THIN DELEGATE (delegates to `ClaudeConnectorBlock::instance()->render()`). The minimal port from T011 is thrown away; ClaudeConnectorTab now matches the NpmTab/ClientsTab thin-delegate shape. **DoD**: content grep = 0 (`<form method="post"` + `wp_nonce_field(` + `<pre>` all = 0 hits in this file); PHPStan L8 + PHPCS green.

**Checkpoint**: US2 delivered. Third-party plugins can invoke the Renderer via shortcode, action hook, or direct static call. F012 gate is enforced (validated in Phase 5). App Password lockdown is enforced (validated in Phase 6).

---

## Phase 5: User Story 3 — F012 settings toggles gate NpmClientBlock + ClaudeConnectorBlock uniformly (Priority: P1) [VALIDATION]

**Goal**: Verify (not implement — implementation lives in T016 + T018) that F012 toggles gate the Renderer output in all contexts.

**Independent Test**: Per US3 acceptance scenarios — flip both toggles off + on, assert disabled notice / full UI in each state, in all 4 contexts (admin, shortcode, action hook, direct static call).

### Verification for User Story 3

- [x] T030 [US3] Verify grep gate on `public/Renderers/` per SEC-013-005 (F012 gate MUST live inside Renderer, not caller): `grep -rn 'acrossai_mcp_npm_login_enabled\|acrossai_mcp_claude_connectors_enabled' public/Renderers/` MUST return hits **ONLY** in `NpmClientBlock.php` and `ClaudeConnectorBlock.php`. NOT in `MCPClientsBlock.php` or `AbstractClientRenderer.php` bodies. **DoD**: grep output matches expectation exactly.

- [x] T031 [US3] Extend `tests/phpunit/Public/Renderers/PublicApiTest.php` (add 5 new test methods):
  1. `test_npm_gate_disabled_shows_notice_hides_config` — set `acrossai_mcp_npm_login_enabled = false`; assert output contains "currently disabled" + link to `?page=acrossai-settings&tab=mcp` + does NOT contain `Configuration JSON` / `Generate New Application Password`; CLI Connection Log ListTable output IS present.
  2. `test_npm_gate_enabled_shows_config` — set option true; assert full config UI + ListTable.
  3. `test_claude_gate_disabled_shows_notice_hides_form` — set `acrossai_mcp_claude_connectors_enabled = false`; assert disabled notice + audit log present + no `save_claude_connector` form.
  4. `test_claude_gate_enabled_shows_form` — full form + audit log.
  5. `test_mcp_clients_not_gated` — set BOTH F012 options to false; assert `MCPClientsBlock::instance()->render()` still produces the full 8-client dispatch (proves no accidental coupling per FR-019).
  **DoD**: all 5 new test methods green.

**Checkpoint**: US3 verified. F012 toggle enforcement is mechanically CI-verifiable.

---

## Phase 6: User Story 4 — App Password lockdown to current user (Priority: P1) [VALIDATION]

**Goal**: Verify (not implement — implementation lives in T021 REST controller + T015 button-disable helper) that App Password minting is locked to `get_current_user_id()` and cross-context nonce replay returns 403.

**Independent Test**: Per US4 acceptance scenarios.

### Verification for User Story 4

- [x] T032 [US4] Extend `PublicApiTest.php` (add 3 new test methods):
  1. `test_generate_button_disabled_when_user_id_mismatches` — instantiate block with `[ 'user_id' => (get_current_user_id() + 1) ]`; assert Generate button HTML contains `disabled` attribute (per FR-024 + SEC-013-002).
  2. `test_rest_endpoint_403_on_user_id_mismatch` — POST to `/wp-json/acrossai-mcp-manager/v1/generate-app-password` with body `user_id` different from current; assert response is 403 (per FR-023 + SEC-013-002).
  3. `test_rest_endpoint_403_on_cross_context_nonce_replay` — mint a nonce for `context='admin'`; POST to endpoint with `context='buddyboss-profile'` + the admin-minted nonce; assert 403 (per FR-023 + SEC-013-001).
  Cite `SEC-013-001` and `SEC-013-002` in the test file docblock. **DoD**: 3 new tests green.

**Checkpoint**: US4 verified. App Password lockdown + cross-context nonce replay defense are mechanically CI-verifiable.

---

## Phase 7: User Story 1 — Complete the 11-tab UI on the per-server-edit page (Priority: P1) 🎯 MVP

**Goal**: Port the remaining 5 tabs (WpCli, Tools, Abilities, McpTracker) + 2 DB-only tabs (UpdateServer, DangerZone) as concrete admin-only tab classes. Enrich OverviewTab from minimal shell to full 147-LOC content. Register all 11 tabs in Registry with correct ordering + visibility rules.

**Independent Test**: Per US1 acceptance scenarios — 9 tabs on plugin-registered server; 11 tabs on database-registered server; each tab body operator-visible content matches reference plugin's UI (Clarifications Q1 allowed F011-native adaptation).

### Implementation for User Story 1

- [x] T033 [US1] Delta edit `admin/Partials/ServerTabs/OverviewTab.php` from T008 minimal shell to full 147-LOC content ported from reference plugin `src/Admin/Settings.php:1101-1247`. Adapt to F011 native shape per Q1 — MCPServer Row properties from F011 are the source of truth; reference plugin's field references adapt to current names. `open_form` + `nonce_field` + `close_form` via AbstractServerTab helpers. `esc_url()` for URL substitutions per SEC-012-008 + B6/S5. **DoD**: manual smoke — Overview tab shows enriched content matching reference plugin's screenshot; PHPStan L8 + PHPCS green.

- [x] T034 [P] [US1] Full write: `admin/Partials/ServerTabs/WpCliTab.php` (NEW). Namespace + extends AbstractServerTab. `slug() = 'wp-cli'`. Port from reference lines 1762-1880 (admin-only render, no Renderer). Uses `AbstractServerTab::open_form` + `nonce_field` where needed. Adapt to F011 native shape. **DoD**: no raw `<form method="post">` / `wp_nonce_field(`; PHPStan L8 + PHPCS green.

- [x] T035 [P] [US1] Full write: `admin/Partials/ServerTabs/ToolsTab.php` (NEW). `slug() = 'tools'`. Port from reference lines 1893-1963. Admin-only render. **DoD**: content grep = 0; PHPStan L8 + PHPCS green.

- [x] T036 [P] [US1] Full write: `admin/Partials/ServerTabs/AbilitiesTab.php` (NEW). `slug() = 'abilities'`. Port from reference lines 1981-2134. **Guard the entire `render_body()` with `if ( ! function_exists( 'wp_get_abilities' ) ) { render soft notice + return; }`** per FR-005 (planning doc TASK-6). Preserve `class_exists( '\AcrossAI_Abilities_Manager\Includes\Runtime' )` guard per D8. Adapt to F011 native. `esc_url()` for URL substitutions per SEC-012-008. **DoD**: function_exists gate at top of render_body; PHPStan L8 + PHPCS green.

- [x] T037 [P] [US1] Full write: `admin/Partials/ServerTabs/McpTrackerTab.php` (NEW). `slug() = 'mcp-tracker'`. Port from reference lines 2293-2379. Detection-only render (checks `class_exists( '\WPVMCPT\Plugin' )` per D8). No forms. **DoD**: PHPStan L8 + PHPCS green.

- [x] T038 [US1] Full write: `admin/Partials/ServerTabs/UpdateServerTab.php` (NEW). `slug() = 'update-server'`. Override `visible_for()`:
  ```php
  public function visible_for( array $server ): bool {
      return 'database' === ( $server['registered_from'] ?? '' );
  }
  ```
  Port from reference lines 2390-2510. Form-based edit for server metadata. Uses `MCPServerQuery::instance()->update_item( $id, ... )` — reuse F011 API; do NOT re-implement. **DoD**: `visible_for` returns true only for database-registered; PHPStan L8 + PHPCS green.

- [x] T039 [US1] Full write: `admin/Partials/ServerTabs/DangerZoneTab.php` (NEW). `slug() = 'danger-zone'`. Override `visible_for()` same as UpdateServerTab. Port from reference lines 2524-2592. Delete-with-confirmation form. Uses `MCPServerQuery::instance()->delete_item( $id )`. **DoD**: `visible_for` restricted; PHPStan L8 + PHPCS green.

- [x] T040 [US1] Delta edit: `admin/Partials/ServerTabs/Registry.php`. Register all 11 tabs into `all_tabs()` in canonical order: `[ OverviewTab, NpmTab, ClientsTab, ClaudeConnectorTab, WpCliTab, ToolsTab, AbilitiesTab, AccessControlTab, McpTrackerTab, UpdateServerTab, DangerZoneTab ]`. **DoD**: exact-count grep `grep -rEn "class .*Tab extends AbstractServerTab" admin/Partials/ServerTabs/` returns **exactly 11 hits**; PHPStan L8 + PHPCS green.

- [x] T041 [US1] Extend `RegistryTest.php` with 4 new test methods per US1 + FR-004..006:
  1. `test_all_tabs_returns_11_ordered` — asserts slug order `[ 'overview', 'npm', 'clients', 'claude-connector', 'wp-cli', 'tools', 'abilities', 'access-control', 'mcp-tracker', 'update-server', 'danger-zone' ]`.
  2. `test_all_slugs_unique` — asserts count equals count of distinct slugs.
  3. `test_visible_tabs_returns_9_when_plugin_source` — pass `[ 'id' => 1, 'registered_from' => 'plugin' ]`; assert 9 tabs (no UpdateServer, no DangerZone).
  4. `test_visible_tabs_returns_11_when_database_source` — pass `[ 'id' => 2, 'registered_from' => 'database' ]`; assert 11 tabs.
  **DoD**: 4 tests green.

- [x] T042 [US1] Manual smoke test on live WP — verify the 11-tab UI:
  - Plugin-registered server (id=1): 9 tabs visible in the expected order; each renders operator-facing content matching reference plugin's screenshots modulo F011-native adaptations (Q1); Update Server + Danger Zone absent from nav.
  - Database-registered server (id=2): 11 tabs including Update Server + Danger Zone.
  - MCP Clients sub-nav routes via `?tab=clients&client=<slug>` — default first client on absent/invalid; browser back/forward preserves state.
  - Save on each editable form: nonce validates, data persists, redirect fires.
  Record smoke evidence in `docs/planings-tasks/013-per-server-tabs-refactor.md` under a "US1 Smoke Evidence" section. **DoD**: all 6 checks pass; evidence recorded.

**Checkpoint**: US1 delivered. 11-tab UI matches reference plugin (modulo F011-native adaptations). MVP is functionally complete.

---

## Phase 8: Polish — DRY sweep + memory hygiene + changelog + final gates

**Purpose**: Enforce zero-duplication invariants + capture DECs + update INDEX + verify all cross-cutting gates green.

- [x] T043 [P] Verify all 5 grep gates from spec's "regression grep-gates" block:
  ```
  # 1. 4 old method names — expect 0 hits
  grep -rEn "render_general_tab|render_access_control_tab|render_claude_connector_tab|render_tokens_tab" --include='*.php' admin/
  # 2. Legacy uppercase namespace — expect 0 hits
  grep -rn 'ACROSSAI_MCP_MANAGER\\\\' --include='*.php' admin/ includes/ public/ acrossai-mcp-manager.php
  # 3. Exact tab count — expect 11 hits
  grep -rEn "class .*Tab extends AbstractServerTab" admin/Partials/ServerTabs/
  # 4. No raw form in tab bodies — expect 0
  grep -cE '<form method="post"' admin/Partials/ServerTabs/*.php
  # 5. No raw nonce in tab bodies — expect 0
  grep -rn 'wp_nonce_field(' admin/Partials/ServerTabs/*.php
  ```
  Plus 3 additional F013 grep gates:
  ```
  # 6. Zero-duplication client-content in tab classes — expect 0 in the 3 thin-delegate tabs
  grep -cE '<pre>|<textarea>|Configuration JSON' admin/Partials/ServerTabs/{NpmTab,ClientsTab,ClaudeConnectorTab}.php
  # 7. F012 option grep — expect hits ONLY in NpmClientBlock + ClaudeConnectorBlock (2 files)
  grep -rn 'acrossai_mcp_npm_login_enabled\|acrossai_mcp_claude_connectors_enabled' public/Renderers/
  # 8. @experimental docblock — expect present on every public method/hook/filter/shortcode in public/Renderers/ + includes/REST/ClientRendererController.php (SEC-013-004)
  grep -rn '@experimental May change without notice before 1.0.0' public/Renderers/ includes/REST/ClientRendererController.php
  ```
  Diff results against expected. **DoD**: all 8 grep gates match expected outputs.

- [x] T044 [P] DRY sweep: read all 11 files under `admin/Partials/ServerTabs/*.php` and identify any repeated HTML shell (>3 lines identical across 2+ tabs) or repeated `esc_*` idiom clusters. Promote to `AbstractServerTab` protected method. Common candidates: JSON-config block header, empty-state notice, client-label pair rendering. **DoD**: after sweep, at least 3 protected helpers exist on AbstractServerTab; no duplicate HTML block appears in >1 tab file.

- [x] T045 [P] Whole-plugin PHPStan L8: `vendor/bin/phpstan analyse --level=8 --no-progress`. **DoD**: exit 0.

- [x] T046 [P] Whole-plugin PHPCS on F013-touched surface: `vendor/bin/phpcs admin/Partials/ServerTabs/ public/Renderers/ includes/REST/ClientRendererController.php`. **DoD**: 0 errors, 0 warnings on all new files; baseline unchanged on modified files (Settings.php + Main.php).

- [x] T047 [P] Whole-plugin `php -l`: `find includes admin public *.php -name '*.php' -type f | xargs -I{} php -l {}`. **DoD**: zero syntax errors.

- [x] T048 [P] Append `docs/memory/DECISIONS.md`: **DEC-SERVER-TAB-CLASS-HIERARCHY (Active — Feature 013)**. Rule: any multi-tab admin surface uses the AbstractServerTab template-method pattern (abstract slug/label/visible_for/render_body + shared helpers) with a Registry singleton for dispatch. Canonical for future admin sections that grow beyond 3 tabs. Codifies DRY across tab renderers. **DoD**: entry present; markdown valid.

- [x] T049 [P] Append `docs/memory/DECISIONS.md`: **DEC-CLIENT-RENDERER-PUBLIC-API (Active — Feature 013)**. Rule per Clarifications Q3 + FR-016a: any admin surface showing MCP client config content MUST render via `public/Renderers/`. Public API includes (a) static `render()` method, (b) `acrossai_mcp_render_client_block` action hook, (c) `acrossai_mcp_client_block_context` filter, (d) `acrossai_mcp_client_classes` filter, (e) shortcodes. `@experimental May change without notice before 1.0.0`. Security: cap-check via context.cap, cross-context nonce binding (server_id + context slug), App Password locked to `get_current_user_id()` at UI + REST layers. Accepted §IV DataForm carve-out per DEC-VENDOR-SETTINGS-TAB-INTEGRATION precedent. **DoD**: entry includes @experimental clause + §IV carve-out reaffirmation.

- [x] T050 [P] Append `docs/memory/INDEX.md`: 2 new DEC rows (DEC-SERVER-TAB-CLASS-HIERARCHY, DEC-CLIENT-RENDERER-PUBLIC-API) + 1 Security Review row (`docs/security-reviews/2026-07-03-013-per-server-tabs-refactor-plan.md | plan | 2026-07-03 | LOW | C:0 H:0 M:0 L:3 | A01,A03,A05,A09`) + optional WORKLOG milestone row IF a durable non-obvious lesson surfaced (candidate: byte-identity cross-context test pattern). **DoD**: `grep -c 'DEC-SERVER-TAB-CLASS-HIERARCHY\|DEC-CLIENT-RENDERER-PUBLIC-API' docs/memory/INDEX.md` returns 2; security review row present.

- [x] T051 [P] Delta edit `README.txt` Unreleased changelog. Add bullet per FR-016a experimental notice:
  > `* Ported 7 additional per-server-edit tabs (Overview enriched, npm, MCP Clients, WP-CLI, Tools, Abilities, MCP Tracker) plus 2 database-registered-only tabs (Update Server, Danger Zone) from the reference plugin into a new per-tab class hierarchy under admin/Partials/ServerTabs/. Refactored the existing 4 tabs (General→Overview, Tokens, Access Control, Claude Connector) into the same shape. Extracted the three client-configuration blocks (npm, MCP Clients, Claude Connector) into a new public Renderer layer under public/Renderers/ with a public API surface (static render() method + acrossai_mcp_render_client_block action hook + acrossai_mcp_client_block_context filter + acrossai_mcp_client_classes filter + optional shortcodes) so third-party plugins (BuddyBoss, WooCommerce, other AcrossAI-family plugins) can embed the same UI on their own admin or frontend surfaces with zero code duplication. The public API is @experimental May change without notice before 1.0.0. Restored CliAuthLogListTable + added ConnectorAuditLogListTable as per-server tab inspectors under DEC-ADMIN-SURFACE-PRUNE-CLI-AUTH-LOG's blessed reintroduction path.`
  **DoD**: bullet present in Unreleased section.

- [x] T052 [P] Delta edit `docs/planings-tasks/README.md`: append F013 row alongside F012. **DoD**: `grep -c '013-per-server-tabs-refactor' docs/planings-tasks/README.md` returns at least 1.

- [x] T053 Final whole-plugin gate + post-merge verification. Run:
  - `vendor/bin/phpstan analyse --level=8 --no-progress` — 0 errors
  - PHPCS on F013 net-new surface — 0 errors 0 warnings
  - `vendor/bin/phpunit --testsuite admin --bootstrap tests/bootstrap-wp.php` — RegistryTest + AbstractServerTabTest + AbstractClientRendererTest + PublicApiTest all green
  - All 8 grep gates from T043
  - `find includes admin public *.php uninstall.php -name '*.php' | xargs php -l` — zero syntax errors
  Diff outputs recorded in `specs/013-per-server-tabs-refactor/post-merge-verification.txt`. Include a "Manual smoke deferrals" section listing US1 T042 evidence + US5 T014 evidence + any US2/US3/US4 smoke evidence collected. **DoD**: all 5 checks green; verification file present.

**Checkpoint**: F013 complete. Every §VII DoD gate green; memory coherent; changelog reflects the ship; DEC-SERVER-TAB-CLASS-HIERARCHY + DEC-CLIENT-RENDERER-PUBLIC-API captured; INDEX rows present.

---

## Dependencies & Execution Order

### Phase dependencies

- **Phase 1 Setup (T001-T003)**: T001 baseline blocks T043 gate #1/#2. T002 blocks T006/T007/T023/T024/T041.
- **Phase 2 Foundational (T004-T007)**: T004 blocks EVERY tab class (T008-T011, T027-T029, T033-T039); T005 blocks T012+T040 and tab dispatch tests; T006/T007 depend on T004+T005.
- **Phase 3 US5 (T008-T014)**: T008-T011 parallel (independent files); T012 depends on T008-T011; T013 depends on T012; T014 manual smoke depends on T013.
- **Phase 4 US2 (T015-T029)**: T015 (AbstractClientRenderer) blocks T016+T017+T018 (Blocks); T019+T020 (ListTables) parallel with T015-T018; T021 (REST controller) depends on T015; T022 (Main.php Loader) depends on T015+T016+T017+T018+T021; T023+T024 (tests) depend on T015-T022; T025+T026 (docs) parallel with the rest; T027+T028+T029 (thin delegates) depend on T016+T017+T018 respectively.
- **Phase 5 US3 (T030-T031)**: depend on T016+T018 (F012 gates land there); T031 test depends on T024 (PublicApiTest file exists).
- **Phase 6 US4 (T032)**: depends on T015+T021+T024.
- **Phase 7 US1 (T033-T042)**: T033 depends on T008 (edit shell); T034-T039 parallel (independent files); T040 depends on T033-T039; T041 depends on T040 + T006; T042 manual smoke depends on T040.
- **Phase 8 Polish (T043-T053)**: T043-T047 mostly parallel (grep + static gates); T048-T052 parallel (docs); T053 depends on all prior.

### User story dependencies

- **US5 (P2)** is FOUNDATIONAL — validates the AbstractServerTab shape with zero UI regression. Blocks US1 (which needs the same base to hang 7 more tabs off).
- **US2 (P1)** delivers the Renderer layer. Also indirectly delivers US3 (gate is inside Renderer body) and US4 (lockdown is inside REST controller). Verified in Phase 5/6.
- **US3 + US4 (P1)** are validation phases; implementation lives in US2 tasks.
- **US1 (P1) MVP** depends on Phase 4 (US2) because 3 of the 11 tabs are thin delegates to Renderer Blocks. Also depends on Phase 3 (US5) because it shares the Registry.

### Within each user story

- Tests written concurrently with implementation but MUST FAIL before implementation lands (TDD-lite).
- Class + wiring before smoke tests.
- Grep gates run last within each phase.
- Story complete before moving to next.

### Parallel opportunities

- **Within Phase 3 US5**: T008-T011 are 4 file-level creates across different files — all `[P]` parallel.
- **Within Phase 4 US2**: T015 blocks the 3 Blocks. Once T015 lands, T016+T017+T018 parallel + T019+T020 (ListTable ports) parallel + T025+T026 (docs) parallel.
- **Within Phase 7 US1**: T034-T037 are 4 admin-only tab ports — all `[P]` parallel.
- **Within Polish**: T043-T047 (grep + static gates) parallel; T048-T052 (docs) parallel.

---

## Parallel example: Phase 4 US2 Renderer layer

```bash
# After T015 (AbstractClientRenderer) lands, launch 5 parallel-safe writes:
Task: "Write NpmClientBlock.php with F012 gate + CLI log helper (T016)"
Task: "Write MCPClientsBlock.php with client_classes filter + sub-nav (T017)"
Task: "Write ClaudeConnectorBlock.php with F012 gate + audit log helper (T018)"
Task: "Port CliAuthLogListTable.php (T019)"
Task: "Port ConnectorAuditLogListTable.php (T020)"

# Then T021 (REST controller) + T022 (Main.php Loader wiring) in sequence.
# T023+T024 (PublicApiTests) depend on the full stack landing.
# T025+T026 (docs/integrations/) can run anytime after T015 (parallel with everything else).
```

---

## Implementation strategy

### MVP first (US2 → US1)

The MVP is US1 (admin sees the 11-tab UI). However, US1's 3 client tabs are thin delegates to Renderer Blocks. So the natural MVP delivery order is:

1. Foundational (Phase 2) — T004-T007
2. US5 validation refactor (Phase 3) — T008-T014
3. US2 Renderer layer + REST + tests (Phase 4) — T015-T029
4. US3 + US4 verification (Phases 5-6) — T030-T032
5. US1 completion — port remaining 7 tabs (Phase 7) — T033-T042
6. Polish (Phase 8) — T043-T053

**STOP + VALIDATE at end of Phase 3** — US5 shape validation confirms the base class is right before porting 7 more tabs on top.
**STOP + VALIDATE at end of Phase 4** — Renderer layer works via PublicApiTest byte-identity assertion before wiring the admin tabs.
**STOP + VALIDATE at end of Phase 7 T042** — full 11-tab UI works on live WP before polish + commit.

### Incremental delivery (single-PR shape, matches F012)

F013 is a single-PR feature. Every task can be a separate commit; total commit count ~53. Constitution §VII per-task gate ensures every commit is PHPStan L8 + PHPCS green.

### Parallel team strategy

With 2+ developers, after T004+T005 land:
- Developer A: Phase 3 US5 (T008-T014) → Phase 7 US1 tab ports (T033-T039)
- Developer B: Phase 4 US2 Renderer layer (T015-T024) → Phase 5+6 validation (T030-T032)
- Developer C: docs/integrations/ (T025+T026) + T041 RegistryTest expansion + Polish docs (T048-T052)
- Team converges at T042 manual smoke + T053 final gate.

---

## Notes

- **[P] tasks = different files, no dependencies**. Verified per task.
- **[Story] label maps task to spec.md user story**. Verification tasks (Phase 5+6) carry `US3` / `US4` labels even though implementation lives in `US2` tasks — this reflects "who benefits from this verification."
- **Every task's DoD includes PHPStan L8 + PHPCS on touched surface** per Constitution §VII per-task gate.
- **Commit after each task** (or logical parallel batch within a phase). Do not batch across phases.
- **Stop at checkpoints** (end of US5, end of US2, end of US1 MVP, end of Polish) to validate.
- **Avoid**: emitting raw `<form method="post">` or `wp_nonce_field()` in any tab subclass body (grep gate at T043); putting client-config HTML (`<pre>`, `<textarea>`, `Configuration JSON`) in NpmTab/ClientsTab/ClaudeConnectorTab bodies (grep gate at T043); allowing legacy uppercase namespace to leak in during reference plugin port (grep gate at T043); hardcoding `manage_options` in Renderer (must use `$context['cap']`); using `wp_get_current_user()->ID` instead of `get_current_user_id()` in REST permission_callback (SEC-013-002); allowing the F012 option names to appear in `MCPClientsBlock.php` or `AbstractClientRenderer.php` bodies (grep gate at T030).
