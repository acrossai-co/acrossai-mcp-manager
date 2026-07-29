# Feature Specification: Per-Server Shortcode + Block Embeds Tab

**Feature Branch**: `036-shortcode-block-embeds`
**Created**: 2026-07-27
**Status**: Draft
**Input**: User description: Add a new per-server admin tab labeled "Embeds" under `?page=acrossai_mcp_manager&action=edit&server=<id>` that lets site administrators enable/disable frontend shortcode + block output for that server, with per-transport sub-toggles for NPM methods, MCP Clients, and AI Connectors. Master toggle default OFF (fresh install ships zero shortcode output for every server). Persist per-server settings in new BerlinDB junction table `wp_acrossai_mcp_server_embed_transports` (presence model per DEC-TOOL-SELECTION-PRESENCE-MODEL) plus new `embeds_enabled TINYINT(1) DEFAULT 0` column on `wp_acrossai_mcp_servers` (via D28 3-part schema-drift contract). Create `AbstractEmbedTransport` base class under `includes/Embeds/` — abstract methods `get_transport_key()`, `get_checkbox_label()`, `get_priority()`; base owns rendering + persistence + `is_enabled_for_server()` static helper. Ship 3 built-in concrete transports (`NpmEmbedTransport`, `ClientEmbedTransport`, `AiConnectorEmbedTransport`). Add filter `acrossai_mcp_embed_transports` for third-party transport registration (D35 canonical enumeration pattern — mirrors F034 `get_all_registered_clients()` line-for-line). New `EmbedsTab` under `admin/Partials/ServerTabs/` (DEV5 hand-rolled form exception — 4th consumer, D13 escalation candidate). Add `[acrossai_mcp_embed]` shortcode gated by master toggle AND per-transport toggle AND F015 access control cascade. Consumes `ConnectionMethodRegistry::get_all()` (F035) as its DTO source. New `embeds` PHPUnit suite. Class ships `final` per D36. See `docs/planings-tasks/037-shortcode-block-embeds.md` for the full engineering brief (note: brief filename says F037, spec dir uses `036-` per next-sequential numbering after F035 — same pattern as the F034/F035 lineage divergence, no functional impact).

## Clarifications

### Session 2026-07-27

- Q: Transport-key naming convention — align with F035 DTO `category` field (singular) or F035 `get_all()` array keys (plural)? → A: **Singular** (`npm`, `client`, `ai_connector`) — matches F035 DTO `category` field so consumers holding a DTO can call `is_enabled_for_server( $server_id, $dto['category'] )` with zero translation. Class names become `NpmEmbedTransport`, `ClientEmbedTransport`, `AiConnectorEmbedTransport`. English-plural checkbox labels ("MCP Clients") with singular keys is standard WP convention (matches F017 ability, F020 tool). F035's plural array keys are a display quirk of the `get_all()` composition; F037 aligns with the DTO field values which are the actual per-item contract.
- Q: Third-party transport row lifecycle when the companion plugin that registered the transport is deactivated (or uninstalled) — do junction rows persist, get auto-pruned, or get shown as orphans in the UI? → A: **Persist silently.** Rows in `wp_acrossai_mcp_server_embed_transports` for a since-missing `transport_key` remain untouched. `AbstractEmbedTransport::get_all_registered_transports()` skips missing FQNs silently (SEC-013-008 pattern), so the Embeds tab does not render a checkbox row for the missing transport — from the admin's perspective the toggle disappears. If the companion plugin is reactivated, `get_all_registered_transports()` sees the FQN again → the checkbox row reappears with its previously-saved state intact. Matches WordPress convention (`wp_options` and plugin metadata are NOT auto-cleared on plugin deactivation). Ship an OPTIONAL cleanup helper `AbstractEmbedTransport::garbage_collect_orphans(): int` that returns the count of pruned rows — companion plugins CAN call this from their own `uninstall.php`, or a future `wp acrossai embeds gc` WP-CLI command can invoke it. F037 itself performs NO automatic cleanup. Documented trade-off: if a plugin is uninstalled + reinstalled later, the old settings return — admins wanting a clean slate must invoke the helper explicitly.
- Q: Observability — should admin toggle changes fire `do_action` for audit purposes? → A: **Two granular events** per save. `acrossai_mcp_embed_master_toggled( int $server_id, bool $enabled, int $user_id )` fires when the master `embeds_enabled` value changes; `acrossai_mcp_embed_transport_toggled( int $server_id, string $transport_key, bool $enabled, int $user_id )` fires once per changed transport row. Matches F015 D19 fail-open observability + F030 admin-toggle audit patterns. Enables WP audit-log plugins + SIEM integrations to record "admin enabled `buddyboss-profile` on server `team-support` at 2026-07-27 14:23" without diffing before/after arrays. Companion plugins ALSO get a hook to react (e.g., pre-warm caches when their transport is enabled per-server). Actions fire only on actual value CHANGES — no-op saves emit nothing.
- Plan-review SEC-037-001 (2026-07-27 security-review): FR-018 tightened — nonce action string MUST be server-scoped (`'acrossai_mcp_embeds_save_' . $server_id`). Direct application of B37 F032 cross-server bypass defense; prevents nonce replay across servers even though `manage_options` is site-wide.
- Plan-review SEC-037-002 (2026-07-27 security-review): `AbstractEmbedTransport::get_all_registered_transports()` comparator MUST cast `get_priority()` return via `(int)` before `usort` — a companion plugin returning non-int would otherwise fatal the admin tab render. See `contracts/AbstractEmbedTransport.contract.md` §`get_all_registered_transports()`.
- Post-implementation bugfix B1 (2026-07-27 `/speckit-analyze` runtime notice): FR-008 regex widened from `/\A[a-z0-9-]{1,64}\z/` (F034 pattern, hyphens only) to `/\A[a-z0-9_-]{1,64}\z/` (adds underscore). Root cause: Q1 clarification aligned F037 transport keys with F035 DTO `category` field values (including `ai_connector`), but the regex was copy-pasted from F034 without re-validating against the new key set — F034's built-in slugs use hyphens only, so the F034 regex was fine for F034 but silently dropped the `ai_connector` key at F037 runtime. Feature was BROKEN end-to-end for the AI Connectors category until this fix. Bug surfaced via a live `_doing_it_wrong` notice on the user's local site. Prevention: `ConcreteTransportsTest::test_transport_key_matches_regex` would have caught it pre-merge if the WP-bootstrap test suite had run locally.
- Q: The initial implementation used a hand-rolled PHP admin form (DEV5 exception) + inline vanilla JS to sync sub-toggles when master changed. The `disabled` attribute couldn't update in real-time without JS. Should we (a) keep the vanilla JS fix, (b) pivot to React with POST-form save unchanged, or (c) pivot fully to React with REST-based save? → A: **Option C — Full React with REST-based save.** Aligns with F017 Abilities + F020 Tools per-server admin UI patterns (canonical for any 3+ field per-server admin surface with real-time interactivity). Establishes the pattern for the eventual Phase-2 block-editor block. Codified as **D37 / DEC-ADMIN-UI-REACT-FIRST** — new admin UIs MUST use React with sanctioned `@wordpress/*` packages; if React is inappropriate (RARE), use vanilla WP admin PHP + core JS + `wp-ajax` (matching how Abilities/Tools work); NEVER hand-rolled forms + vanilla JS as a substitute for React. **DEV5 hand-rolled form exception NO LONGER APPLIES to F037** — the exception's consumer count drops from 4 back to 3 (F013 Update Server + F013 Danger Zone + F030 Access Control override). D13 escalation candidate is retracted for F037.

## Post-Plan Pivots

Three material architectural pivots landed post-`/speckit-plan` and post-`/speckit-implement` (in addition to the Q4 React+REST pivot above). This section is the canonical listing; every drifted FR/SC below cross-references back here.

### Pivot A — Per-DTO gate redesign (2026-07-27)

**What changed**: Every per-transport toggle became per-DTO. The initial design gated whole categories on/off — one toggle per transport_key. The shipped design gates individual F035 DTOs on/off — one toggle per `(transport_key, dto_slug)` pair. So enabling "MCP Clients" globally is replaced by enabling "Claude Desktop", "VS Code", etc. individually.

**Impact**: `AbstractEmbedTransport::is_enabled_for_server()` signature widened from `(int $server_id, string $transport_key)` to `(int $server_id, string $transport_key, string $dto_slug)`. `acrossai_mcp_embed_transport_toggled` action gained a `$dto_slug` argument (5 args total). The 12-combination SC-004 cascade matrix expanded to per-DTO scale. FR-005 storage model became a slug-membership list, not a boolean flag per transport.

**Motivation**: The BuddyBoss add-on need — grant "expose Claude Desktop but not Cursor to public frontend" without forcing all-or-nothing per category. Retrofit gave admins finer control at the cost of a bigger toggle surface.

### Pivot B — Meta-table storage refactor (2026-07-27)

**What changed**: The `wp_acrossai_mcp_server_embed_transports` junction table + the `embeds_enabled` column on `wp_acrossai_mcp_servers` were BOTH retired. Storage moved to a WP-canonical meta table `wp_acrossai_mcp_servers_meta` (shape mirrors `wp_postmeta`: `meta_id`, `server_id`, `meta_key`, `meta_value`). Two meta keys carry F037 state:
- `_embeds_enabled` — presence + value `'1'` = master toggle ON; absence/`'0'` = OFF
- `_embeds_clients` — JSON-encoded blob per Pivot A: `{ "npm": 0|1, "mcp-client": [slug…], "connectors": [slug…] }`

**Impact**: FR-002, FR-005, FR-016 all describe storage that was never shipped. `MCPServer\Table::upgrade_to_1_1_3` was created then reversed via `upgrade_to_1_1_4` (DROP column). `ServerEmbedTransports` BerlinDB module (4 files) was created then deleted. `MCPServerMeta` BerlinDB module (4 files) added instead — reusable per-server key-value primitive open to future features beyond F037.

**Motivation**: User request. Simplifies operational tooling (`SHOW CREATE TABLE` inspection identical to `wp_postmeta`); no `UNIQUE(server_id, transport_key)` proliferation as third-party transports register; single meta_key holds the full per-DTO state map atomically.

### Pivot C — `AbstractReactMountServerTab` extraction (2026-07-27)

**What changed**: A new intermediate abstract class `AbstractReactMountServerTab extends AbstractServerTab` was extracted to own the four subsystems every React-mount tab needs: asset enqueue, REST GET/POST controller, storage-state contract, self-registration via a single `register()` entry point. EmbedsTab became a subclass of this base, folding in the content of the deleted `EmbedsController`. The `admin/Main.php::maybe_enqueue_embeds_app()` helper was deleted.

**Impact**: New reusable primitive shipped inside acrossai-mcp-manager, positioned as the sanctioned extension surface for third-party companion plugins to add their own React-mount tabs. Not in the original F037 scope; emerged from a design-question thread after Pivot B. Adds FR-028..FR-030 covering the base's contract.

**Motivation**: User request — third-party plugins should be able to extend one class and get a fully-working per-server admin tab (with its own JS/CSS/REST/storage/noscript fallback) end-to-end, mirroring how a plugin author extends `AbstractMCPClient` today.

## User Scenarios & Testing *(mandatory)*

### User Story 1 — Site administrator gates shortcode output per server (Priority: P1)

A site administrator wants to expose the server "team-support" as an embeddable connection method on the front-end for members of a BuddyBoss group, but keep the server "internal-dev" invisible to the public. They visit the server-edit page for "team-support", switch to the new "Embeds" tab, check the master toggle "Enable frontend embeds for this server", then check the per-transport sub-toggles for the categories they want to expose (e.g., "MCP Clients" ON, "AI Connectors" ON, "NPM Methods" OFF). They save. On the frontend, `[acrossai_mcp_embed server="team-support" category="client"]` renders the appropriate connection block; `[acrossai_mcp_embed server="team-support" category="npm"]` renders nothing (silent). For server "internal-dev", the same shortcodes silently render nothing because its master toggle is OFF (default).

**Why this priority**: This is the whole reason the feature exists. Without it, F035's discovery API has no admin-facing gate for what companion plugins are allowed to render publicly. The BuddyBoss add-on cannot ship without per-server output controls.

**Independent Test**: Load the server-edit page for a fresh server. Assert master toggle exists, is unchecked by default, and disables sub-toggle interaction. Check master; assert sub-toggles become enabled + editable. Check MCP Clients sub-toggle. Save. Reload page — state persisted. Place `[acrossai_mcp_embed server="<slug>" category="client"]` in a page. Frontend view: block renders. Change category to "npm". Frontend view: nothing renders.

**Acceptance Scenarios**:

1. **Given** a fresh WordPress install with F037 shipped and a new MCP server "foo", **When** an administrator visits the server-edit page and clicks the "Embeds" tab, **Then** they see the master toggle unchecked + three per-transport sub-toggles (NPM Methods, MCP Clients, AI Connectors) all disabled (greyed out).
2. **Given** the master toggle is checked and MCP Clients sub-toggle is checked (and saved), **When** the shortcode `[acrossai_mcp_embed server="foo" category="client"]` is rendered on a frontend page, **Then** the connection block for MCP clients renders.
3. **Given** the master toggle is checked but the NPM Methods sub-toggle is unchecked, **When** the shortcode `[acrossai_mcp_embed server="foo" category="npm"]` is rendered on a frontend page, **Then** nothing renders (silent, no error, no placeholder markup).
4. **Given** the master toggle is unchecked, **When** any embed shortcode for that server is rendered on a frontend page, **Then** nothing renders regardless of per-transport sub-toggle state.

---

### User Story 2 — Third-party plugin registers a custom transport category (Priority: P2)

A BuddyBoss add-on developer wants their custom "BuddyPress profile MCP badge" to appear in the Embeds tab as a fourth sub-toggle alongside NPM Methods / MCP Clients / AI Connectors. They write a subclass of `AbstractEmbedTransport` with three overrides — `get_transport_key(): 'buddyboss-profile'`, `get_checkbox_label(): __( 'BuddyPress profile MCP badge', 'my-plugin' )`, `get_priority(): 40` — and register it via `acrossai_mcp_embed_transports` filter. The Embeds tab now shows the new checkbox row automatically; saving the tab persists a presence row in the junction table; the add-on's frontend renderer calls `AbstractEmbedTransport::is_enabled_for_server( $server_id, 'buddyboss-profile' )` to gate its own output.

**Why this priority**: This is the extensibility contract that unblocks the BuddyBoss add-on's ability to add its own transport categories without patching this plugin. Symmetric with F034 (`acrossai_mcp_client_classes`) and F021 (`acrossai_mcp_manager_connector_profiles`) extension seams. Not blocking core F037 shipping (base plugin ships with only the 3 built-in categories), but required for the motivating consumer.

**Independent Test**: Register an mu-plugin subclassing `AbstractEmbedTransport` with the above 3 overrides and hooking `acrossai_mcp_embed_transports`. Visit any server-edit → Embeds tab. Assert the new checkbox row appears with the expected label + in the correct priority slot (between MCP Clients priority-20 and AI Connectors priority-30 → wait, priority 40 → after AI Connectors). Save. Assert `AbstractEmbedTransport::is_enabled_for_server( $server_id, 'buddyboss-profile' )` returns true; presence row exists in `wp_acrossai_mcp_server_embed_transports`.

**Acceptance Scenarios**:

1. **Given** a companion plugin registers a valid `AbstractEmbedTransport` subclass via `acrossai_mcp_embed_transports`, **When** the Embeds tab renders, **Then** the new checkbox row appears with the subclass's declared label at its declared priority slot.
2. **Given** the same registration + the master toggle is ON + the new sub-toggle is ON, **When** `AbstractEmbedTransport::is_enabled_for_server( $server_id, 'buddyboss-profile' )` is called, **Then** it returns `true`.
3. **Given** an invalid contribution (non-subclass FQN, malformed key not matching `[a-z0-9_-]{1,64}`, or duplicate key), **When** the tab renders, **Then** the invalid entry is silently dropped + `_doing_it_wrong` fires under `WP_DEBUG` (SEC-013-008 pattern inherited from F034).

---

### User Story 3 — Frontend rendering respects the security cascade (Priority: P1)

A visitor lands on a public page containing `[acrossai_mcp_embed server="foo" category="client"]`. The shortcode's output is gated by a cascade of three checks in this order: (1) F037 master toggle ON for server "foo", (2) F037 per-transport toggle ON for "client", (3) F015 access control (`\AcrossAI_MCP_Access_Control::user_has_server_access( $user_id, $server_id )`) permits the current user (may be anonymous) access to server "foo". Any check failing silently drops output — no error, no placeholder markup, no `<!-- comment -->` leak. Every check missing (F015 wrapper absent because the access-control plugin isn't installed) fails-open per D19.

**Why this priority**: Security posture is non-negotiable. F037 must not bypass any existing gate (F015 access control) — it adds a NEW gate on top. Failing to compose these gates correctly would leak server-metadata to unauthorized visitors.

**Independent Test**: Install F015 access control + configure server "foo" to allow role "member" only. Log in as an "author" role (not "member"). Master toggle ON, per-transport ON. Frontend view of `[acrossai_mcp_embed server="foo" category="client"]`: nothing renders (F015 wins the cascade). Uninstall F015 (fail-open path); repeat: block renders (F037 gates pass, F015 wrapper absent → default true).

**Acceptance Scenarios**:

1. **Given** F015 access control denies the current user for server "foo", **When** the embed shortcode for server "foo" renders, **Then** nothing renders regardless of F037 toggle state.
2. **Given** F015 wrapper is absent (plugin not installed) AND F037 toggles are ON, **When** the shortcode renders, **Then** the block renders (fail-open per D19).
3. **Given** an unknown `server="<bogus-slug>"` attribute, **When** the shortcode renders, **Then** nothing renders (silent, no PHP notice).

---

### Edge Cases

- **New server has no toggle state saved yet**: master toggle defaults to unchecked (0 in the new column); no rows exist in the junction table → `is_enabled_for_server()` returns false for every transport key. Consistent with "default OFF" invariant.
- **Server deletion**: rows in `wp_acrossai_mcp_server_embed_transports` orphaned; MUST be cleaned up via a companion cleanup hook (per DEV feedback: on `deleted_post` for the server post-type OR direct hook if servers are BerlinDB rows without a post_type). Add a task in Phase 1.
- **Master ON but every per-transport OFF**: shortcodes silently render nothing for every category — this is intentional; admin can pre-enable master before wiring per-transport toggles in a staging workflow.
- **Third-party transport removed after being saved to junction table** (Clarifications Q2 / FR-022 / FR-023): junction rows for a since-removed `transport_key` remain untouched in the database — `is_enabled_for_server()` still returns based on the persisted row value BUT the shortcode's F035 `find()` call returns `null` for a companion-plugin-owned category the base plugin doesn't know about, so downstream rendering is silent regardless. Tab UI does not render the checkbox (base class enumeration skips missing FQNs). No automatic cleanup — companion plugins CAN opt into cleanup via `AbstractEmbedTransport::garbage_collect_orphans()` from their `uninstall.php`.
- **Shortcode inside a block-editor page rendered in the admin preview**: same gate cascade applies (admin preview does NOT bypass F015 or F037 checks). Preview shows what the frontend visitor would see.
- **Anonymous visitor + F015 access control ON**: F015 evaluates against anonymous user; if server allows public access, shortcode renders; else silent.

---

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The plugin MUST add a new tab named `embeds` (label: "Embeds") to the per-server-edit page (`?page=acrossai_mcp_manager&action=edit&server=<id>`) via `AbstractServerTab` extension per DEC-SERVER-TAB-CLASS-HIERARCHY. The tab MUST appear in the existing tab navigation alongside Update Server, Access Control, Abilities, Tools, Danger Zone, etc.
- **FR-002** (revised per Pivot B): The Embeds tab MUST render a master enable/disable toggle labeled "Enable frontend embeds for this server" with default state OFF. Persisted as a meta row in `wp_acrossai_mcp_servers_meta` with `meta_key = '_embeds_enabled'`, `meta_value = '1'` when ON; row is DELETED entirely when OFF (presence-model per DEC-TOOL-SELECTION-PRESENCE-MODEL). *Original spec described `embeds_enabled TINYINT(1) DEFAULT 0` column on `wp_acrossai_mcp_servers`; column was added at `MCPServer\Table::$version=1.1.3`, then DROPped at `1.1.4` when Pivot B moved storage to the meta table.*
- **FR-003**: When the master checkbox is UNCHECKED, per-transport sub-toggles MUST be disabled (greyed out, non-interactive) but their persisted state MUST be preserved (no destructive reset on master-toggle-off).
- **FR-004**: When the master checkbox is CHECKED, per-transport sub-toggles MUST be enabled (interactive). Each sub-toggle represents one transport category from the registered `AbstractEmbedTransport` set.
- **FR-005** (revised per Pivots A + B): The plugin MUST persist per-DTO enable state in a WP-canonical meta table `wp_acrossai_mcp_servers_meta` (shape: `meta_id`, `server_id`, `meta_key`, `meta_value` — mirrors `wp_postmeta`). One meta row per server carries the full per-DTO state as a JSON-encoded blob at `meta_key = '_embeds_clients'` with shape:
  ```json
  {
    "npm": 0|1,
    "mcp-client": ["claude-desktop", "vscode", ...],
    "connectors": ["chatgpt", "grok", ...]
  }
  ```
  Category-key `npm` uses an int shorthand (single-item semantic per `AbstractEmbedTransport::is_single_item()`); other categories use array-of-enabled-slugs (presence-model — slug present = ON, absent = OFF). The whole `_embeds_clients` row is DELETED when every category is empty (presence-model at the row level too). *Original spec described a dedicated junction table `wp_acrossai_mcp_server_embed_transports` with `UNIQUE(server_id, transport_key)` — module was created (`includes/Database/ServerEmbedTransports/{Schema,Table,Row,Query}.php`) then deleted per Pivot B. `MCPServerMeta` module ships in its place.*
- **FR-006**: The plugin MUST provide an abstract base class `AcrossAI_MCP_Manager\Includes\Embeds\AbstractEmbedTransport` with abstract methods `get_transport_key(): string`, `get_checkbox_label(): string`, and concrete-with-default methods `get_priority(): int` (default 100 — matches F034), `get_description(): string` (default '', translated).
- **FR-007**: The abstract base MUST expose a public `const DEFAULT_TRANSPORT_CLASSES` listing the 3 built-in concrete FQNs (`NpmEmbedTransport`, `ClientEmbedTransport`, `AiConnectorEmbedTransport`) as the enumeration seed.
- **FR-008**: The abstract base MUST expose `public static function get_all_registered_transports(): array` that: (a) fires `acrossai_mcp_embed_transports` filter seeded with `DEFAULT_TRANSPORT_CLASSES`, (b) validates each FQN is a subclass of `AbstractEmbedTransport` (silent-skip invalid per SEC-013-008), (c) instantiates each subclass, (d) validates each `get_transport_key()` against regex **`/\A[a-z0-9_-]{1,64}\z/`** — underscore included to accommodate F035 DTO `category` field values like `ai_connector` (F037-only divergence from F034's hyphens-only regex) — `_doing_it_wrong` under `WP_DEBUG` on violators + skip, (e) dedups by key with later-wins (`_doing_it_wrong` under `WP_DEBUG` on duplicates), (f) sorts by `(priority ASC, key ASC)`, (g) returns `array_values`. Mirrors F034 `AbstractMCPClient::get_all_registered_clients()` shape line-for-line EXCEPT for the widened regex character class.
- **FR-009** (revised per Pivots A + B): The abstract base MUST expose `public static function is_enabled_for_server( int $server_id, string $transport_key, string $dto_slug ): bool` that returns `true` ONLY when: (a) the meta row `(server_id, '_embeds_enabled')` exists with value `'1'` (master gate), AND (b) the decoded `_embeds_clients` JSON blob contains the `$dto_slug` inside its category array under the transport's `get_storage_key()` (single-item transports use the int `1` shorthand instead — see `AbstractEmbedTransport::entry_enables_slug()` for the uniform helper). Short-circuits to `false` on either miss. Memoized per-request per R2; cache key includes all three arguments. *Original spec had 2-arg signature `(int, string)`; expanded per Pivot A to gate individual DTOs, not whole categories.*
- **FR-010**: The plugin MUST ship 3 concrete built-in transport classes under `includes/Embeds/`:
  - `NpmEmbedTransport` — key `npm`, priority 10, label `__( 'NPM Methods', 'acrossai-mcp-manager' )`
  - `ClientEmbedTransport` — key `client`, priority 20, label `__( 'MCP Clients', 'acrossai-mcp-manager' )`
  - `AiConnectorEmbedTransport` — key `ai_connector`, priority 30, label `__( 'AI Connectors', 'acrossai-mcp-manager' )`
- **FR-011**: Transport keys align 1:1 with F035 DTO `category` field values (`npm`, `client`, `ai_connector`) per Clarifications Q1 (Session 2026-07-27). A consumer holding an F035 DTO can call `AbstractEmbedTransport::is_enabled_for_server( $server_id, $dto['category'] )` directly — zero translation required. Note: F035's `ConnectionMethodRegistry::get_all()` returns plural array keys (`'npm' => …, 'clients' => …, 'ai_connectors' => …`) as a composition-shape quirk; F037 aligns with the per-item DTO `category` values, not the composition array keys. Consumers iterating `get_all()` should use `$dto['category']` when calling into F037, not the outer array key.
- **FR-012**: Every `AbstractEmbedTransport` subclass MUST be declared `final class` per D36 (extension via filter, not subclass-of-subclass).
- **FR-013** (revised per Pivot A): The plugin MUST add a shortcode `[acrossai_mcp_embed server="<slug>" category="<key>" slug="<optional-dto-slug>"]` that: (a) resolves server by `server_slug` → `server_id` via existing `MCPServer\Query::get_by_slug()` (silent no-render on miss), (b) resolves the DTO(s) via `\AcrossAI_MCP_Manager\Public\Discovery\ConnectionMethodRegistry::instance()->find( $category, $slug )` (single-DTO if `slug` attr given) OR the appropriate `get_*_methods()` for the whole category, (c) verifies F015 access control via `\AcrossAI_MCP_Access_Control::user_has_server_access( $user_id, $server_id )` when the wrapper exists (fail-open per D19 when absent) — silent no-render on miss, (d) **for each DTO in the resolved list**, calls `AbstractEmbedTransport::is_enabled_for_server( $server_id, $category, $dto['slug'] )` per Pivot A — DTOs whose gate returns `false` are DROPPED from the output; DTOs whose gate returns `true` proceed to render, (e) renders via an escape-at-render output template with `esc_html()` / `esc_attr()` per SEC-035-002 preservation invariant. When the resolved DTO list is empty (either upstream miss or every gate returned false), the shortcode renders zero bytes silently.
- **FR-014**: The shortcode rendering MUST fire a filter `acrossai_mcp_embed_render_html` on the final HTML string (pre-echo) so companion plugins can customize markup without patching this plugin.
- **FR-015**: The plugin MUST register a new PHPUnit suite `embeds` in `phpunit.xml.dist` pointing at `tests/phpunit/Embeds/`, with matching CI step in `.github/workflows/phpunit.yml` (follow the F030/F035 test-infrastructure precedent). Suite uses `tests/bootstrap-wp.php` — F037 touches BerlinDB, WP option storage, F015 access-control wrapper, shortcode API, all requiring WP context.
- **FR-016** (revised per Pivot B): The plugin MUST reconcile database schemas on `admin_init@3` via `Main::reconcile_database_schemas()` per D28. Actual shipped reconciliation path:
  - `MCPServer\Table::$version` bumped through `1.1.2 → 1.1.3` (add `embeds_enabled` column) → `1.1.4` (DROP the column + DROP the retired `wp_acrossai_mcp_server_embed_transports` table + `delete_option` for its stale db_version).
  - `MCPServerMeta\Table::$version` starts at `1.0.0` (fresh WP-canonical meta table shape) → `1.0.1` (DELETE stale `_embed_dto:*` rows from the intermediate per-DTO-row iteration before consolidation into `_embeds_clients` JSON blob).
  - Both tables' `maybe_upgrade()` fires on `admin_init@3`, phantom-version-guarded per F011 SEC-011-002. *Original spec described only `upgrade_to_1_1_2`; the shipped path spans 4 version bumps due to Pivots A + B iterations.*
- **FR-017**: The plugin MUST clean up junction rows for a deleted server. Hook: `acrossai_mcp_server_deleted` action (fired by existing server-deletion path) OR a direct call from the deletion handler → `ServerEmbedTransports\Query::delete_by_server_id( $server_id )`. Avoids orphan-row accumulation.
- **FR-018** (revised per Clarifications Q4 — Option B pivot): The Embeds tab MUST expose a REST controller `\AcrossAI_MCP_Manager\Includes\REST\EmbedsController` registering GET+POST routes at `/acrossai-mcp-manager/v1/servers/{server_id}/embeds` (matches F017 Abilities + F020 Tools URL pattern exactly). Both routes MUST have explicit `permission_callback` verifying `manage_options` (S2). CSRF protection uses WP core `wp_rest` nonce delivered via `X-WP-Nonce` header (WP core `apiFetch.createNonceMiddleware`). SEC-037-001 server-scoping is subsumed by the URL's `{server_id}` path parameter (cross-server bypass impossible — POST to Server B's URL cannot affect Server A's row). React app under `src/js/embeds.js` mounts at `#acrossai-mcp-embeds-root` in the tab body; save POSTs to the REST endpoint via `apiFetch`. NO admin-post handler, NO hand-rolled form, NO server-scoped custom nonce action (WP core `wp_rest` nonce is authoritative). Matches F017 `AbilitiesController` + F020 `ToolsController` pattern verbatim per D37.
- **FR-019**: The shortcode MUST NOT require any capability (frontend-facing, may be anonymous); security gate is entirely on the F015 access control cascade (FR-013.c).
- **FR-020**: The plugin MUST NOT touch F035's `ConnectionMethodRegistry` or its files. F037 is purely a CONSUMER of F035's discovery API. No new methods on `ConnectionMethodRegistry`, no re-implementation of its filters.
- **FR-021**: The plugin MUST NOT touch any existing `AbstractServerTab` subclass, `AbstractMCPClient`, `AbstractConnectorProfile`, `AbstractConnectorProfile` subclasses, F015 access control wrapper, or any file under `includes/OAuth/`. F037 adds ONLY new files under `includes/Embeds/`, `admin/Partials/ServerTabs/EmbedsTab.php`, `public/Renderers/EmbedBlock/`, `includes/Database/ServerEmbedTransports/`, `tests/phpunit/Embeds/`, plus edits `phpunit.xml.dist` + `.github/workflows/phpunit.yml` + `Main.php` (schema reconciliation + shortcode registration + tab registration wiring per A1) + `includes/Database/MCPServer/Table.php` (D28 version bump only).
- **FR-022**: The plugin MUST NOT auto-prune junction rows for missing transport keys (Clarifications Q2). Rows persist silently in `wp_acrossai_mcp_server_embed_transports` when a companion plugin that registered a transport is deactivated or uninstalled. `get_all_registered_transports()` skips missing FQNs → the Embeds tab does not render checkbox rows for missing transports. Reactivating the companion plugin restores the checkbox with its previously-saved state intact.
- **FR-023**: The plugin MUST expose an OPTIONAL public static helper `AbstractEmbedTransport::garbage_collect_orphans(): int` that: (a) enumerates every distinct `transport_key` in `wp_acrossai_mcp_server_embed_transports`, (b) computes the set of `transport_key` values currently registered via `get_all_registered_transports()`, (c) DELETEs junction rows whose `transport_key` is NOT in the currently-registered set, (d) returns the count of pruned rows. F037 itself invokes this helper NOWHERE — it is a supported opt-in surface for companion plugins to call from their `uninstall.php` and for a future `wp acrossai embeds gc` WP-CLI command.
- **FR-024** (revised per Pivots A + B): The EmbedsTab REST save handler MUST fire two observability actions (Clarifications Q3) on ACTUAL value changes (no-op saves emit nothing):
  - `do_action( 'acrossai_mcp_embed_master_toggled', int $server_id, bool $enabled, int $user_id )` — fires when the `_embeds_enabled` meta row transitions between present-and-`'1'` ↔ absent for the target server. `$user_id` is `get_current_user_id()`. (Signature unchanged from original spec.)
  - `do_action( 'acrossai_mcp_embed_transport_toggled', int $server_id, string $transport_key, string $dto_slug, bool $enabled, int $user_id )` — fires ONCE per DTO membership transition in `_embeds_clients`. A save that toggles 3 DTOs fires this 3 times; a save that no-ops on 2 and toggles 1 fires this exactly once. `$transport_key` is the transport category (e.g. `'client'`); `$dto_slug` is the F035 DTO slug (e.g. `'claude-desktop'`). **Signature widened per Pivot A** — `$dto_slug` inserted as the 3rd argument (was `(server_id, transport_key, enabled, user_id)` — 4 args).
  Both actions fire AFTER the meta write commits. Fail-forward: an action listener that throws MUST NOT roll back the write (matches F015 D19 pattern). Consumers relying on the pre-Pivot-A 4-arg signature MUST update their listeners. Changelog note called out in README `= Unreleased =`.

### AbstractEmbedTransport extension surface (added per Pivots A + B)

- **FR-025**: The abstract base MUST expose `public function get_storage_key(): string` — returns the category key used inside the `_embeds_clients` JSON blob. Defaults to `$this->get_transport_key()`. Concrete transports MAY override for a friendlier storage-facing alias (e.g. built-in `ClientEmbedTransport` returns `'mcp-client'` for the storage key while its transport_key stays `'client'`; `AiConnectorEmbedTransport` returns `'connectors'`). Companion-plugin transports whose transport_key is already storage-friendly can accept the default.
- **FR-026**: The abstract base MUST expose `public function is_single_item(): bool` — returns `true` when the transport carries a single logical DTO and its storage entry should be represented as an int `1` (present) / absent (off) shorthand rather than an array-of-enabled-slugs. Defaults to `false` (array-of-slugs shape). Only built-in `NpmEmbedTransport` returns `true` today (single npx bridge).
- **FR-027**: The abstract base MUST expose `public function get_dtos(): array` — returns the list of F035 DTOs this transport gates. Default `[]` (companion-plugin transports with no known DTOs render as empty category rows, harmless). Built-in transports override to delegate to `ConnectionMethodRegistry` — e.g. `ClientEmbedTransport::get_dtos()` returns `ConnectionMethodRegistry::instance()->get_clients()`. Consumers iterate the returned list and pass `$dto['slug']` to `is_enabled_for_server()`. Also exposed:
  - `public static function entry_enables_slug( $entry, string $dto_slug, bool $is_single ): bool` — uniform helper for both the runtime gate + REST diff. Callers pass a decoded blob entry (may be null, int, or array) and get a boolean.
  - `public static function get_items_for_server( int $server_id ): array` — fetch + decode the `_embeds_clients` JSON blob.
  - `public static function save_items_for_server( int $server_id, array $items ): void` — encode + write (or delete the row entirely when the array is empty per FR-005 presence semantic).
  - `public static function meta_for( string $transport_key ): array{storage_key: string, is_single: bool}` — memoized runtime lookup used by static callers (public renderers) who don't hold a transport instance.

### AbstractReactMountServerTab reusable primitive (added per Pivot C)

- **FR-028**: The plugin MUST provide an intermediate abstract class `AbstractReactMountServerTab extends AbstractServerTab` at `admin/Partials/ServerTabs/AbstractReactMountServerTab.php`. Owns the four subsystems every React-mount per-server admin tab needs: asset enqueue, REST GET/POST controller, storage-state contract, self-registration via a single `register()` entry point. Documented as the sanctioned extension surface for third-party companion plugins to add their own per-server admin tabs. Class-level docblock includes a copy-pastable "WidgetsTab" third-party consumer example.
- **FR-029**: `AbstractReactMountServerTab::register()` MUST be idempotent (guarded per-class) and wire three hooks:
  1. `admin_enqueue_scripts` → `enqueue_assets_if_active()` (screen + `?tab=` guard, then reads the `*.asset.php` manifest, `wp_enqueue_script/style`, and `wp_localize_script` with the bootstrap payload)
  2. `rest_api_init` → `register_rest_routes()` (GET + POST on `get_rest_route_path()` under `get_rest_namespace()` with `manage_options` capability gate + WP core `wp_rest` nonce via apiFetch middleware)
  3. `acrossai_mcp_manager_server_tabs` filter → append the tab entry, deduped by slug (skips when the tab is already registered as a built-in via `Registry::all_tabs()` — prevents the `_doing_it_wrong` duplicate-slug notice)
- **FR-030**: `AbstractReactMountServerTab` MUST declare two abstract state-contract methods the subclass implements — `get_state_for_server( int $server_id ): array` (consumed by REST GET + bootstrap payload + noscript summary) and `set_state_for_server( int $server_id, array $submitted ): array` (called from REST POST; returns fresh state). The subclass decides schema validation, storage backend, observability firing. Base auto-derives the noscript summary via `summary_rows_from_state( get_state_for_server( $server_id ) )` — subclass MAY override `summary_rows_from_state()` for a domain-shaped view (e.g. `EmbedsTab` returns human-readable "Master toggle: Enabled" + per-transport enabled counts).

### WordPress Requirements

**PHP Version**: PHP 8.1+ (plugin minimum per AGENTS.md).
**WordPress Version**: 6.9+ (plugin minimum per AGENTS.md).
**Multisite**: Single-site (plugin is single-site per AGENTS.md; unchanged by this feature).
**Required Plugins / Packages**: None new. `berlindb/core` already installed.
**Optional Integrations**: `wpboilerplate/wpb-access-control` (F015) — F037's shortcode integrates with the F015 wrapper if present; fails open per D19 when absent.

### Module Placement

**PHP Class(es)**:
- `includes/Embeds/AbstractEmbedTransport.php` (**new**) → namespace `AcrossAI_MCP_Manager\Includes\Embeds` — the abstract base + canonical enumeration + `is_enabled_for_server()` static helper
- `includes/Embeds/NpmEmbedTransport.php` + `ClientEmbedTransport.php` + `AiConnectorEmbedTransport.php` (**new** × 3) → same namespace — the 3 built-in concrete transports (each ~15 lines)
- `includes/Database/ServerEmbedTransports/{Schema,Table,Row,Query}.php` (**new** × 4) → namespace `AcrossAI_MCP_Manager\Includes\Database\ServerEmbedTransports` — BerlinDB junction table
- `admin/Partials/ServerTabs/EmbedsTab.php` (**new**) → namespace `AcrossAI_MCP_Manager\Admin\Partials\ServerTabs` — the tab UI (extends `AbstractServerTab`)
- `public/Renderers/EmbedBlock/EmbedBlockRenderer.php` (**new**) → namespace `AcrossAI_MCP_Manager\Public\Renderers\EmbedBlock` — the shortcode registration + render (`final class` per D36)

**Modified files**:
- `includes/Main.php` — wire tab registration in `define_admin_hooks()`, shortcode registration in `define_public_hooks()` per A1; register new BerlinDB table in `load_hooks()` per DEC-BERLINDB-TABLE-REQUEST-BOOT; register schema reconciliation per D28
- `includes/Database/MCPServer/Table.php` — bump `$version` 1.1.1 → 1.1.2 + register `upgrade_to_1_1_2` callback (D28 3-part contract)
- `includes/Database/MCPServer/Schema.php` — add `embeds_enabled TINYINT(1) NOT NULL DEFAULT 0` column definition
- `phpunit.xml.dist` — add `embeds` testsuite entry
- `.github/workflows/phpunit.yml` — add CI step for `embeds` suite
- `README.txt` — `= Unreleased =` changelog

**Hook Registration**: All new hook registrations happen in `includes/Main.php` per A1 — `add_shortcode( 'acrossai_mcp_embed', ... )` in `define_public_hooks()`, tab registration via `ServerTabRegistry` in `define_admin_hooks()`, schema reconciliation callback in `define_admin_hooks()`. Zero `add_filter`/`add_action`/`add_shortcode` calls in `includes/Embeds/`, `admin/Partials/ServerTabs/EmbedsTab.php`, or `public/Renderers/EmbedBlock/`.

### Admin UI Requirements

**React + `@wordpress/*` packages per D37 / DEC-ADMIN-UI-REACT-FIRST** (post-Q4 pivot). The Embeds tab body is a React app mounted at `#acrossai-mcp-embeds-root` using ONLY sanctioned WP packages: `@wordpress/element`, `@wordpress/api-fetch` (with **nonce middleware only** per B25), `@wordpress/i18n`, `@wordpress/components` (`ToggleControl`, `Notice`, `Spinner`), `@wordpress/dataviews` (`DataViews` + `DataForm` + `filterSortAndPaginate`). Matches F017 Abilities + F020 Tools canonical shape. **DEV5 hand-rolled form exception is EXPLICITLY NOT invoked for F037** — Q4 clarification retracted the 4th-consumer escalation; DEV5's consumer count stays at 3 (Update Server, Danger Zone, Access Control override).

Post-Pivot C: the tab class extends `AbstractReactMountServerTab` (not `AbstractServerTab` directly) — the intermediate base owns the enqueue + REST + storage-state plumbing so the concrete `EmbedsTab` is nearly pure declaration. See FR-028..FR-030.

### REST API Contract

**Post-Q4 pivot**: F037 exposes GET + POST at `/acrossai-mcp-manager/v1/servers/{server_id}/embeds` (path parameter server-scoped per B37 — cross-server bypass structurally impossible). Both routes gate on `manage_options` via `permission_callback` (FR-018 as revised). CSRF via WP core `wp_rest` nonce delivered through `X-WP-Nonce` header (WP core `apiFetch.createNonceMiddleware`). Response shape: `{ master: bool, groups: [{ key, label, priority, dtos: [{ slug, name, icon, enabled }] }] }`. Save request shape: `{ master: bool, items: { <transport_key>: { <dto_slug>: bool } } }` — the outer keys are transport_key (matching `get_transport_key()`), inner keys are DTO slugs. Post-Pivot C: routes are registered by `AbstractReactMountServerTab::register_rest_routes()` via the subclass's `get_state_for_server()` + `set_state_for_server()` methods — not a bespoke `EmbedsController` (that controller was created per Q4 PIVOT-3 then deleted per Pivot C).

### Database / Storage (revised per Pivot B)

**WP-canonical meta table** (justified — the model naturally fits WP `wp_postmeta` shape, is reusable for future per-server settings beyond F037, and eliminates schema drift as third-party transports register):

- **NEW table**: `{wpdb->prefix}acrossai_mcp_servers_meta`
  - Columns: `meta_id BIGINT UNSIGNED PK`, `server_id BIGINT UNSIGNED NOT NULL DEFAULT 0`, `meta_key VARCHAR(255) NULL`, `meta_value LONGTEXT NULL`
  - Indexes: PK(`meta_id`), KEY(`server_id`), KEY(`meta_key(191)`) — prefix index for utf8mb4 safety
  - NO `UNIQUE(server_id, meta_key)` — mirrors `wp_postmeta` convention; single-value semantic enforced in app code (`Query::update_meta()` SELECT-then-INSERT-or-UPDATE)
  - Justification: generic per-server key-value primitive; F037 uses two keys (`_embeds_enabled` for master, `_embeds_clients` for the per-DTO JSON blob); table is open to future features (e.g. per-server API rate limits, per-server webhook URLs) without further schema changes
  - Activation hook: `register_activation_hook()` via plugin activator; BerlinDB `maybe_upgrade()` fires on `admin_init@3` per D28
  - Version bumps: `1.0.0` initial → `1.0.1` (`upgrade_to_1_0_1()` cleans up stale `_embed_dto:*` rows from the intermediate per-DTO-row iteration before Pivot B consolidated to `_embeds_clients` JSON)

- **MODIFIED table**: `{wpdb->prefix}acrossai_mcp_servers`
  - Original design: add `embeds_enabled TINYINT(1) DEFAULT 0` column (`$version` 1.1.2 → 1.1.3 via `upgrade_to_1_1_3()`)
  - **Reverted per Pivot B**: `$version` 1.1.3 → 1.1.4 via `upgrade_to_1_1_4()` — DROP COLUMN `embeds_enabled` + DROP TABLE `wp_acrossai_mcp_server_embed_transports` (retired junction table) + `delete_option('acrossai_mcp_server_embed_transports_db_version')` (stale BerlinDB tracker option)
  - Net effect: `wp_acrossai_mcp_servers` schema unchanged from F036 baseline once the 1.1.3→1.1.4 upgrade completes

- **RETIRED table**: `{wpdb->prefix}acrossai_mcp_server_embed_transports`
  - The original junction table with `(id, server_id, transport_key, is_enabled, date_created, date_modified)` + `UNIQUE(server_id, transport_key)` was created at F037 initial ship then dropped per Pivot B
  - `includes/Database/ServerEmbedTransports/{Schema,Table,Row,Query}.php` module DELETED
  - No data migration from the junction to the new meta blob — the F037 initial ship is not in production (feature branch); dev installs are drop-and-recreate acceptable

### Security Checklist

*(Derived from Constitution §III — verify all that apply)*

- [x] All form/AJAX handlers verify nonce via `wp_verify_nonce()` — EmbedsTab save handler MUST verify nonce (FR-018)
- [x] All admin page renders check capability — EmbedsTab render + save enforce `manage_options` (FR-018)
- [x] All REST routes have explicit `permission_callback` — N/A (no REST routes per FR-019)
- [x] All user input sanitized at system boundary — save handler MUST sanitize checkbox values with `absint()` or `(bool)`
- [x] All output escaped at point of rendering — tab checkboxes + labels MUST use `esc_attr()` / `esc_html_x()` / `checked()` / `esc_html__()`; shortcode output MUST escape DTO string fields per SEC-035-002 preservation invariant
- [x] All DB queries use `$wpdb->prepare()` — BerlinDB Query class handles this natively via typed placeholders
- [x] OAuth tokens / Application Passwords stored hashed — N/A (no token handling)
- [x] File uploads validated — N/A (no file uploads)

### Key Entities

- **Server Embed Configuration** — the per-server enable/disable state for shortcode + block output. Composite state: master toggle (`wp_acrossai_mcp_servers.embeds_enabled`) + per-transport toggles (`wp_acrossai_mcp_server_embed_transports` rows). Consumed by `AbstractEmbedTransport::is_enabled_for_server()` and the `[acrossai_mcp_embed]` shortcode.
- **Embed Transport** — a category of connection method that can be exposed via shortcode/block. Represented at runtime as an `AbstractEmbedTransport` subclass with a `transport_key` (`[a-z0-9_-]+` per FR-008), display label, priority, storage-key alias, single-item flag, DTO list, and default-empty description. Base plugin ships 3 built-in transports (`NpmEmbedTransport`, `ClientEmbedTransport`, `AiConnectorEmbedTransport`); companion plugins add more via `acrossai_mcp_embed_transports` filter.

---

## Success Criteria *(mandatory)*

### Definition of Done Gates

All of the following MUST pass before this feature is considered complete:

- [ ] PHPCS validation: zero errors and zero warnings on all touched files
- [ ] PHPStan level 8: zero errors on all touched files
- [ ] ESLint: N/A (no JS added — pure server-render + `checked()` HTML)
- [ ] PHPUnit tests written and passing for: `AbstractEmbedTransport::get_all_registered_transports()` shape + validation, `is_enabled_for_server()` state matrix (4 combinations of master × per-transport), EmbedsTab render + save (nonce + cap enforced, checkbox state persisted), shortcode gate cascade (master + per-transport + F015 + F015-absent fail-open), 3rd-party transport registration end-to-end
- [ ] New `embeds` PHPUnit suite registered + CI step green
- [ ] Security checklist above: all applicable items verified
- [ ] All hooks wired in `Main.php` per A1 — zero `add_*` calls inside `includes/Embeds/`, `admin/Partials/ServerTabs/EmbedsTab.php`, `public/Renderers/EmbedBlock/`
- [ ] Tab uses DEV5 hand-rolled form exception (documented; consider constitution amendment)
- [ ] No code duplication — F037 delegates to F035 (`ConnectionMethodRegistry`) + F015 (access control) + F034/F021 (via F035's delegation chain)
- [ ] All functions, hooks, and classes prefixed with `acrossai_mcp_`
- [ ] `npm run validate-packages` passes
- [ ] Grep gate: `grep -rEn 'apply_filters.*acrossai_mcp_client_classes|acrossai_mcp_manager_connector_profiles|acrossai_mcp_npm_methods|acrossai_mcp_connection_methods' includes/Embeds/ admin/Partials/ServerTabs/EmbedsTab.php public/Renderers/EmbedBlock/` returns zero hits (F037 consumes F035 via delegation, never re-fires filters)
- [ ] Grep gate: `grep -rEn '\bConnectionMethodRegistry\b' includes/Embeds/` returns zero hits (F037's `includes/Embeds/` domain layer MUST NOT import `public/Discovery/` — one-way layering: only the shortcode renderer in `public/Renderers/EmbedBlock/` imports F035)

### Measurable Outcomes

- **SC-001**: A site administrator can enable shortcode output for a specific server + specific transport category in ≤ 5 clicks (open tab → check master → check sub-toggle → save → verify frontend).
- **SC-002**: Fresh install ships with 0 shortcodes rendering on any frontend page for any server (verified by installing plugin + creating a server + placing shortcode on a page → no output).
- **SC-003** (revised per Pivots A + B + C): Third-party plugin developer can add a new transport category with ≤ **35 lines** of code (an `AbstractEmbedTransport` subclass overriding `get_transport_key`, `get_checkbox_label`, `get_priority`, `get_storage_key`, `get_dtos` — 5 methods — + one `add_filter( 'acrossai_mcp_embed_transports', ... )` call). *Original ceiling was 20 LOC when the base had 3 abstract methods and no storage-strategy override; Pivots A + B added `get_storage_key` and `get_dtos` to the required surface. Verified by writing an mu-plugin exercising User Story 2.* Separately, adding a whole new React-mount admin tab (not just a transport) uses `AbstractReactMountServerTab` per FR-028 and takes ~40 LOC of PHP declaration + the plugin's own JS/CSS build assets.
- **SC-004** (revised per Pivot A): Shortcode output cascade correctness — for every combination of (master, per-DTO, F015-access-control) toggle states, the shortcode renders exactly the subset of DTOs whose gates ALL pass. Reduced to a **sampled matrix**: (master ON/OFF) × (per-DTO ON/OFF for a representative slug in each of the 3 built-in categories) × (F015 present-allows / present-denies / absent) = 3 categories × 12 = 36 combinations, verified by data-provider PHPUnit test that asserts non-empty output IFF all 3 gates pass for at least one DTO in the requested category. *Original SC-004 was 12 combinations (2×2×3, whole-category); Pivot A expanded the matrix to per-DTO scale — full enumeration (N-DTOs × 12) would be brittle to F035 registry growth, so switched to representative sampling per category.*
- **SC-005**: Grep gate on `includes/Embeds/` + `admin/Partials/ServerTabs/EmbedsTab.php` + `public/Renderers/EmbedBlock/`: zero hits for any of the four F035-owned filter names. F037 delegates, never re-fires (mirrors F035's SC-005).
- **SC-006** (revised per Pivot A): Grep gate on the **abstract base** `includes/Embeds/AbstractEmbedTransport.php` only: zero import of `AcrossAI_MCP_Manager\Public\Discovery\ConnectionMethodRegistry`. The abstract base MUST remain a pure-domain state layer that does not reach across into `public/Discovery/`. Concrete `AbstractEmbedTransport` subclasses (built-in `NpmEmbedTransport` / `ClientEmbedTransport` / `AiConnectorEmbedTransport`, plus any companion-plugin subclasses) MAY import `ConnectionMethodRegistry` inside `get_dtos()` to delegate DTO enumeration to the F035 discovery API — this is the shipped pattern and the whole reason `get_dtos()` was added as an override hook per Pivot A. Grep-gate command:
  ```bash
  grep -rEn '\bConnectionMethodRegistry\b' includes/Embeds/AbstractEmbedTransport.php
  ```
  MUST return zero hits (docblock mentions in narrative comments MAY reference the class by name — regex-match on `use` + `::` invocation only). *Original SC-006 gated all of `includes/Embeds/` — pre-Pivot-A, DTO enumeration lived in a switch statement inside `EmbedsController::resolve_dtos_for_transport()`, so no transport class needed F035. Post-Pivot A that switch was deleted and each concrete transport owns its DTO source.*
- **SC-007**: A companion plugin's transport that registers with a colliding key (e.g., duplicates the built-in `npm` key) is silently later-wins per D35 semantic (verified by unit test) with `_doing_it_wrong` under `WP_DEBUG` — behaviour identical to F034's `get_all_registered_clients` dedup.
- **SC-008**: When F015 access control is present AND denies the current user, no shortcode output leaks — verified by test with F015 wrapper stubbed to return false; asserted output is exactly zero bytes.
- **SC-009** (FR-022 / FR-023): After a companion plugin registers a transport, saves state to a server, and then is "deactivated" (unregisters its filter callback), a follow-up call to `AbstractEmbedTransport::get_all_registered_transports()` MUST NOT list the now-missing transport AND the corresponding junction row MUST remain in `wp_acrossai_mcp_server_embed_transports`. A separate call to `AbstractEmbedTransport::garbage_collect_orphans()` MUST return a positive count AND remove the orphan row. A follow-up second call to `garbage_collect_orphans()` MUST return 0 (idempotency).
- **SC-010** (FR-024): Every save that changes at least one toggle value fires exactly the right number of observability actions — verified by a parameterized test with 4 scenarios: (a) toggle master 0 → 1 with no transport changes → 1× `acrossai_mcp_embed_master_toggled` fires, 0× `acrossai_mcp_embed_transport_toggled`; (b) toggle 2 transports with master unchanged → 0× master, 2× transport; (c) toggle master + 3 transports → 1× master, 3× transport; (d) save with zero value changes (no-op form submit) → 0× master, 0× transport (nothing fires). Every action listener that throws is caught by the save handler (fail-forward) — the DB write must have already committed before the action fires.

---

## Assumptions

- The BuddyBoss add-on (the motivating consumer for both F035 and F037) is out of scope for this feature. F037 delivers the per-server admin surface + shortcode; the add-on lives in a separate repository and consumes both `ConnectionMethodRegistry::get_all()` (F035) and `AbstractEmbedTransport::is_enabled_for_server()` (F037) as stable-ish upstream contracts.
- F035 has shipped (0.1.9-eventual, currently on `main` as commit `b9f0029`). F037 depends on F035; opening F037 development against `main` requires F035 to be present, verified.
- Third-party contributions to `acrossai_mcp_embed_transports` are trusted at the "installed by admin" level — same trust anchor as the existing `acrossai_mcp_client_classes` (F034), `acrossai_mcp_manager_connector_profiles` (F021), and F035's `acrossai_mcp_npm_methods` / `acrossai_mcp_connection_methods` seams.
- Block-editor block rendering is DEFERRED to a follow-up minor release (F038 or Phase 2 of F037). Same storage, same enable-gates, different renderer. Shipping shortcode alone keeps the initial F037 review surface bounded.
- No frontend JavaScript needed for the master-toggle-reveals-subs UX in initial ship — server-render with `disabled` attribute + basic vanilla JS toggle is acceptable. Full UX interactivity deferred to a small follow-up feature.
- F015 access control is the ONLY gate composed on top of F037's own toggles at frontend render time. F017 ability exposure and F020 tool curation are NOT gates on F037 — those apply at MCP tool-call time, not at frontend embed time (F037 embeds are static configuration display, not MCP tool invocations).
- Transport-key naming resolved per Clarifications Q1 — singular keys (`npm`, `client`, `ai_connector`) aligned with F035 DTO `category` field values. Consumers pass `$dto['category']` directly into `is_enabled_for_server()` with no translation. F035's plural array keys in `get_all()` are a composition-shape quirk; F037 aligns with the per-item contract, not the array-key naming.
- The `embeds_enabled` column on `wp_acrossai_mcp_servers` uses `TINYINT(1)` (matches existing plugin convention for boolean columns; per B18, cast to `(int)` before strict compare or use `!empty()` for boolean semantics).
- Uninstall path: `uninstall.php` MUST honor the F012 opt-in gate. If opt-in NOT set, the new junction table + column survive; if opt-in set, the table is dropped + the column is left in place (safer than adding an ALTER DROP COLUMN to uninstall, per D21 fresh-install-only retirement pattern's inverse — additive default-OFF stays).
