# Planning: Per-Server Shortcode + Block Embeds Tab (Feature 037)

**Note on numbering**: Brief filed as `037-` because `036-connection-method-discovery-api.md` is the F035 brief (established brief-vs-spec-dir mismatch pattern from F034/F035 lineage). The eventual `/speckit-git-feature` will auto-number the spec dir sequentially after `035-connection-method-discovery-api`, likely producing dir `036-shortcode-block-embeds` — the brief-vs-dir divergence is documented in each spec's Input paragraph and has no functional impact. Resolve at merge if desired.

**Depends on**: F035 shipped (0.1.9) — this feature consumes `ConnectionMethodRegistry::instance()->get_all()` as its enumeration source. Opening F037 development against `main` requires F035 to be present on `main`.

---

## ⚠️ POST-IMPLEMENTATION PIVOT NOTE (2026-07-27)

**This brief was written pre-pivot.** The initial implementation followed the brief's DEV5 hand-rolled form + inline vanilla JS approach, but ran into a real-time sub-toggle sync bug that required JS anyway. That triggered a mid-flight `/speckit-clarify` Q4 that pivoted F037 to **full React + REST** per D37 codification. What SHIPPED:

- **Admin UI**: React app at `src/js/embeds.js` mounted on `<div id="acrossai-mcp-embeds-root">` — uses `@wordpress/components` (`ToggleControl`, `Button`, `Notice`, `Spinner`), `@wordpress/element`, `@wordpress/api-fetch` (nonce middleware only per B25), `@wordpress/i18n`
- **Save path**: REST controller `\AcrossAI_MCP_Manager\Includes\REST\EmbedsController` registering `GET+POST /acrossai-mcp-manager/v1/servers/{server_id}/embeds` — matches F017 Abilities + F020 Tools URL pattern verbatim
- **Auth**: `permission_callback` verifies `manage_options`; WP core `wp_rest` nonce via `X-WP-Nonce` header (SEC-037-001 server-scoping obsoleted — REST URL's `{server_id}` path parameter IS the tenant scope, structurally preventing cross-server bypass)
- **DEV5 no longer applies** — F037 dropped from consumer count; back to 3 (Update Server, Danger Zone, Access Control override — all single-submit surfaces per narrowed DEV5)

**Sections below that reference the DEV5 hand-rolled form approach + server-scoped nonce action + `handle_save_embeds()` are historical.** For the authoritative shipped design, consult `specs/036-shortcode-block-embeds/{spec,plan,security-constraints,tasks}.md` + `docs/memory/DECISIONS.md` D37. This note is preserved because the brief's structural decisions (BerlinDB junction table, `AbstractEmbedTransport` D35 pattern, 3-gate cascade in the shortcode, observability actions, GC helper, F035 delegation) all shipped as designed — only the admin-UI shape pivoted.

---

## Intro

Add a new per-server admin tab under `?page=acrossai_mcp_manager&action=edit&server=<id>` that lets site administrators control which connection methods surface via **shortcodes and blocks** on the WordPress frontend. First iteration ships shortcodes; block-editor blocks are a Phase-2 extension of the same subsystem (same storage, same enable-gates, different renderer). Tab default state: **globally disabled per server** — a fresh install ships zero shortcode output for every server.

The tab structure is: (1) a **master toggle** ("Enable frontend embeds for this server" — default OFF); when ON it reveals (2) **per-transport sub-toggles** — one checkbox each for NPM / MCP Clients / AI Connectors (each defaulting to OFF). Admin sets the shape of what companion plugins' shortcodes are allowed to render on that server's public surfaces. This is a companion to F015 access control (server-level allow), F017 ability exposure (per-server ability allow), F020 tool curation (per-server tool allow) — F037 adds the fourth per-server allow surface: **frontend embed exposure**.

The feature ships with an **extensibility base class** so third-party plugins can register their own transport categories with three overrides (`get_transport_key()`, `get_checkbox_label()`, `get_priority()`) and inherit all rendering + persistence + gate-lookup logic from the base. Direct application of D35 (self-contained-subsystem-contract) — abstract base owns the shared logic; concrete subclasses declare only the identity + display metadata; filter-based enumeration registry composes them. Grep gates enforce delegation-not-re-implementation as with F034 + F035.

## Motivating Consumer

The planned BuddyBoss add-on (F035's originally-named motivating consumer) needs TWO things: (a) a **discovery API** to enumerate available connection methods → **F035 delivers this** via `ConnectionMethodRegistry`, (b) a **per-server enable/disable admin surface** so a site's members' BuddyPress profile shortcodes can be gated by the site admin per-server per-transport → **F037 delivers this**. Together F035 + F037 give the add-on the complete server-side story; the add-on itself ships in the BuddyBoss add-on repository.

## Speckit Workflow

### 1. Create the feature branch

```
/speckit-git-feature "shortcode-block-embeds"
```

### 2. Create the spec

```
/speckit-specify
```

#### Detailed Description for /speckit-specify

> Add a new per-server admin tab named **"Embeds"** to `?page=acrossai_mcp_manager&action=edit&server=<id>` that lets site administrators enable / disable frontend shortcode + block output for that server, with per-transport sub-toggles for NPM methods, MCP Clients, and AI Connectors. Tab default state: globally disabled per server. First transport surface: a `[acrossai_mcp_embed]` shortcode + supporting output helpers. Block editor block is a Phase-2 extension of the same subsystem (identical storage, identical enable-gates, different renderer).
>
> Persist per-server settings in a NEW BerlinDB junction table `wp_acrossai_mcp_server_embed_transports(server_id BIGINT UNSIGNED, transport_key VARCHAR(64), is_enabled TINYINT(1), UNIQUE(server_id, transport_key))` per DEC-TOOL-SELECTION-PRESENCE-MODEL — presence row means "enabled for this transport on this server". Master enable/disable lives on `wp_acrossai_mcp_servers` as a new `embeds_enabled TINYINT(1) DEFAULT 0` column via D28's 3-part schema-drift-reconciliation contract. Row-per-transport table justified by extensibility ask: third-party plugins register new transport keys → new rows, never new columns.
>
> Create a new abstract base `AbstractEmbedTransport` under `includes/Embeds/` with three abstract methods a subclass MUST override: `get_transport_key(): string` (stable machine ID, `[a-z0-9-]{1,64}`), `get_checkbox_label(): string` (translated), `get_priority(): int` (display order, default 100 — matches F034 priority pattern). Base class owns four responsibilities: (1) rendering the checkbox row inside `EmbedsTab::render_body()`, (2) reading/writing the presence row on save, (3) exposing a static `is_enabled_for_server( int $server_id, string $transport_key ): bool` helper for frontend renderers to gate on, (4) participating in the canonical enumeration registry.
>
> Ship 3 concrete built-in transport classes: `NpmEmbedTransport` (key=`npm`, priority 10), `ClientsEmbedTransport` (key=`clients`, priority 20), `AiConnectorsEmbedTransport` (key=`ai_connectors`, priority 30) — one per F035 discovery category. Each is ~10 lines (identity metadata only).
>
> Add ONE new filter `acrossai_mcp_embed_transports` fired inside `AbstractEmbedTransport::get_all_registered_transports()` (canonical enumeration per D35 — mirrors F034 `AbstractMCPClient::get_all_registered_clients` line-for-line). Seed with 3 built-ins; validate FQN + slug regex + dedup + sort by (priority ASC, key ASC).
>
> Add a new `EmbedsTab` under `admin/Partials/ServerTabs/` extending `AbstractServerTab` (F013 hierarchy per DEC-SERVER-TAB-CLASS-HIERARCHY). Register in `ServerTabRegistry` alongside existing tabs. Small-form-count tab → DEV5 hand-rolled form exception applies (this is the 4th consumer under DEV5, matching D13 escalation threshold — consider promoting to a §IV constitution amendment).
>
> Add a `[acrossai_mcp_embed server="<slug>" category="<npm|client|ai_connector>"]` shortcode that (a) resolves server by slug → server_id, (b) verifies `EmbedsTab` master toggle is ON for that server, (c) verifies the per-transport toggle is ON, (d) resolves the DTO(s) from `ConnectionMethodRegistry::instance()->find($category, $slug)` or per-category `get_*_methods()`, (e) renders via a filterable output template with escape-at-render enforcement per F035 SEC-035-002 preservation invariant. All render helpers under `public/Renderers/EmbedBlock/` (new subdirectory). Frontend-facing → no admin cap required; shortcode is public-read by design.
>
> Extend existing security stack: shortcode output MUST be additionally gated by F015 access control (if the current user is not allowed on this server, render nothing — silent, not error). F017 ability exposure + F020 tool curation are NOT gates on F037 — those apply at MCP tool-call time, not at frontend embed time.
>
> Register a new PHPUnit suite `embeds` under `tests/phpunit/Embeds/` with matching CI job. Uses `tests/bootstrap-wp.php` (touches WP options, BerlinDB, F015 access control stubs).
>
> Reuse existing utilities: `AbstractServerTab` (F013), `ConnectionMethodRegistry` (F035), F015 access control wrapper. Reuse D35 self-contained-subsystem-contract, D28 BerlinDB schema-drift reconciliation, DEC-TOOL-SELECTION-PRESENCE-MODEL, DEV5 hand-rolled tab exception. Never re-implement any pattern established by prior features.

### 3. Plan

```
/speckit-architecture-guard-governed-plan
```

Constitution Check gates: §I Modular (new `includes/Embeds/` + `admin/Partials/ServerTabs/EmbedsTab.php` + `public/Renderers/EmbedBlock/`), §III Security First (frontend shortcode = additive gate stack: F037 toggle AND F015 access control), §IV DEV5 hand-rolled form exception (4th consumer — D13 escalation candidate), §V filter-driven extensibility, §VI reuse F035 + F013 + F015 wrappers, §VII DoD gates unchanged.

### 4. Tasks

```
/speckit-architecture-guard-governed-tasks
```

Expected phase breakdown (~35-45 tasks):
- **Phase 1 Setup** — pre-flight baseline, screenshot pre-existing tab layout for regression diff
- **Phase 2 Foundational** — D28 3-part contract for `wp_acrossai_mcp_servers.embeds_enabled` column bump; new `wp_acrossai_mcp_server_embed_transports` BerlinDB module (Schema/Table/Row/Query, 4 files ~200 LOC); PHPUnit `embeds` suite registration + CI job
- **Phase 3 US1 (P1)** — `AbstractEmbedTransport` base class + `AbstractEmbedTransport::get_all_registered_transports()` canonical enumeration + `is_enabled_for_server()` static helper + 3 built-in concrete subclasses (NpmEmbedTransport, ClientsEmbedTransport, AiConnectorsEmbedTransport) + tests
- **Phase 4 US2 (P1)** — `EmbedsTab` under `admin/Partials/ServerTabs/`; render checkbox rows via base class; nonce + cap + save handler; register in `ServerTabRegistry`; A1-conformant hook wiring in Main.php
- **Phase 5 US3 (P2)** — `[acrossai_mcp_embed]` shortcode + `public/Renderers/EmbedBlock/` output helpers; F015 access-control gate integration; render byte-identity tests
- **Phase 6 US4 (P3)** — third-party extensibility validation: register a fake transport via `acrossai_mcp_embed_transports` filter, verify checkbox appears with correct label + priority, verify save + read paths work end-to-end
- **Phase 7 Polish** — grep gates (equivalent to F035 SC-005 for delegation: no re-firing `acrossai_mcp_client_classes` / `acrossai_mcp_manager_connector_profiles` / F035 filters); memory hygiene; README changelog; PHPCS + PHPStan + PHPUnit; manual DOM verification

### 5. Implement

```
/speckit-architecture-guard-governed-implement
```

Follows the F035 shipping pattern: batch class-write for the base + concretes when practical, TDD for validators + save handlers.

### 6. Review + Ship

```
/speckit-analyze
/speckit-security-review-staged
/speckit-memory-md-capture-from-diff
/speckit-git-commit → PR → merge → release chain (version bump + tag + GitHub release)
```

---

## TASK-1 — BerlinDB additions

- **Schema bump on `wp_acrossai_mcp_servers`**: add `embeds_enabled TINYINT(1) NOT NULL DEFAULT 0` via D28 3-part contract (`$version` bump 1.1.1 → 1.1.2, `$upgrades = [ '1.1.2' => 'upgrade_to_1_1_2' ]`, callback runs idempotent `INFORMATION_SCHEMA` existence check + `ALTER TABLE ADD COLUMN`)
- **New BerlinDB module** `includes/Database/ServerEmbedTransports/{Schema, Table, Row, Query}.php`:
  - Columns: `id` (BIGINT PK), `server_id` (BIGINT NOT NULL, FK-ish), `transport_key` (VARCHAR(64) NOT NULL), `is_enabled` (TINYINT(1) NOT NULL DEFAULT 0), `created` (DATETIME `created` flag), `modified` (DATETIME `modified` flag — B21 compliance)
  - Indexes: `UNIQUE(server_id, transport_key)` (presence-model enforcement per DEC-TOOL-SELECTION-PRESENCE-MODEL); `KEY(server_id)` for per-server lookups
  - Table instantiation at request time via `Main::load_hooks()` per DEC-BERLINDB-TABLE-REQUEST-BOOT
  - Register in `Main::reconcile_database_schemas()` at `admin_init@3` per D28
  - Query methods: `is_enabled_for_server( int $server_id, string $transport_key ): bool`, `set_enabled_for_server( int $server_id, string $transport_key, bool $enabled ): bool`, `get_all_for_server( int $server_id ): array<string, bool>`

## TASK-2 — Abstract base + concrete transports

- `includes/Embeds/AbstractEmbedTransport.php`:
  - Abstract: `get_transport_key(): string`, `get_checkbox_label(): string`
  - Concrete-with-default: `get_priority(): int { return 100; }`, `get_description(): string { return ''; }`
  - Public const `DEFAULT_TRANSPORT_CLASSES = [ NpmEmbedTransport::class, ClientsEmbedTransport::class, AiConnectorsEmbedTransport::class ]`
  - `public static function get_all_registered_transports(): array` — mirrors `AbstractMCPClient::get_all_registered_clients()` line-for-line: fire filter → validate FQN → validate key regex `/\A[a-z0-9-]{1,64}\z/` (`_doing_it_wrong` under `WP_DEBUG`) → dedup by key with `_doing_it_wrong` on duplicates → `usort` by `(priority ASC, key ASC)` → `array_values`
  - `public static function is_enabled_for_server( int $server_id, string $transport_key ): bool` — reads `wp_acrossai_mcp_servers.embeds_enabled` first (master gate), then `wp_acrossai_mcp_server_embed_transports` presence row (per-transport gate); short-circuits to false on either miss
- `includes/Embeds/NpmEmbedTransport.php`, `ClientsEmbedTransport.php`, `AiConnectorsEmbedTransport.php` — one file each, ~15 lines: identity + label + priority overrides. Keys map 1:1 to F035 categories.

## TASK-3 — EmbedsTab admin surface

- `admin/Partials/ServerTabs/EmbedsTab.php` extends `AbstractServerTab`:
  - `get_slug(): string { return 'embeds'; }`
  - `get_label(): string { return __( 'Embeds', 'acrossai-mcp-manager' ); }` (default; consider "Shortcodes & Blocks" if UX testing prefers longer + more discoverable)
  - `render_body( array $server ): void` — DEV5 hand-rolled form: master checkbox row, then iterate `AbstractEmbedTransport::get_all_registered_transports()` and render one checkbox per transport (initially greyed out when master is OFF, JS-toggle enable when master is checked)
  - `handle_save( array $post_data, int $server_id ): void` — nonce verify + cap check (`manage_options`) + save master toggle + iterate transports and upsert each presence row
- Register in `ServerTabRegistry::instance()->register( EmbedsTab::instance() )` — sibling tab pattern with existing Update Server / Access Control / Danger Zone tabs
- Wire in `Main::define_admin_hooks()` per A1 (no ctor hook registration; class exposes `register_hooks( Loader $loader )` no — direct wire per Main pattern)

## TASK-4 — Shortcode + frontend renderer

- `public/Renderers/EmbedBlock/EmbedBlockRenderer.php`:
  - Registers `[acrossai_mcp_embed]` shortcode via `add_shortcode` (called from Main.php per A1 — pass through Loader → shortcode registration is A1-transitive per D17)
  - Attributes: `server="<slug>"` (required), `category="<npm|client|ai_connector>"` (required), `slug="<method-slug>"` (optional — filter to one method)
  - Gate cascade: (1) resolve server_id from slug, (2) `AbstractEmbedTransport::is_enabled_for_server( $server_id, $category )` — silent no-render on miss, (3) F015 access control check via shared `\AcrossAI_MCP_Access_Control::user_has_server_access( $user_id, $server_id )` — silent no-render on miss, (4) `ConnectionMethodRegistry::instance()->find( $category, $slug )` OR `get_*_methods()` per category
  - Render output via `esc_html()` / `esc_attr()` — no raw DTO field emission (SEC-035-002 preservation invariant); consumer of F035 owns escape
  - Filterable output template `acrossai_mcp_embed_render_html` for companion plugins to customize markup
- Zero admin surface; zero token handling; zero DB writes (read-only frontend).

## TASK-5 — Third-party extensibility test

- Register mu-plugin adding a fake transport `BuddyBossEmbedTransport` via `acrossai_mcp_embed_transports` filter (key `buddyboss-profile`, label "BuddyPress profile MCP badge", priority 40).
- Verify: checkbox appears in tab UI with correct label + priority slot; save creates presence row in `wp_acrossai_mcp_server_embed_transports`; `AbstractEmbedTransport::is_enabled_for_server(1, 'buddyboss-profile')` returns true post-save; hypothetical shortcode with `category="buddyboss-profile"` renders when both master + per-transport toggles are ON.

## TASK-6 — Memory hygiene

- Propose `D37 / DEC-F037-EMBEDS-TAB-BASE-CLASS-PATTERN` — abstract base + filter registry + per-server presence storage; generalizable to any future per-server admin toggle surface with third-party category extensibility. Cross-references D35 (self-contained subsystem contract), DEC-TOOL-SELECTION-PRESENCE-MODEL (storage shape), DEC-SERVER-TAB-CLASS-HIERARCHY (tab pattern), DEV5 (hand-rolled form exception).
- Consider `constitution v1.2.0` amendment — DEV5 has 4 consumers now (Update Server, Danger Zone, Access Control override, Embeds) — meets D13 escalation threshold. Escalate to §IV third exception paragraph OR keep as DEV5 with 4th precedent noted.
- If any new grep gate is added (e.g. equivalent of F035 SC-005 for the F037 filter delegation), document it as a task in Phase 7 with the exact command spelled out per B26 anti-pattern prevention.

---

## Manual Verification Checklist

1. **Pre-refactor baseline**: capture DOM of the server-edit page pre-F037 to confirm no unrelated tab drift.
2. **Master toggle default OFF**: fresh install → new server → visit Embeds tab → master checkbox unchecked → all sub-checkboxes greyed out.
3. **Master toggle ON reveals sub-toggles**: check master → sub-toggles become enabled + editable; save; reload — state persisted.
4. **Per-transport toggle**: enable npm; disable clients + ai_connectors; save; try shortcode `[acrossai_mcp_embed server="foo" category="npm"]` → renders; `[acrossai_mcp_embed server="foo" category="client"]` → silent no-render.
5. **F015 access control cascade**: disable current user's access to server "foo" via Access Control tab; shortcode with all Embeds toggles ON → silent no-render (F015 wins).
6. **Third-party transport**: register mu-plugin adding fake transport; checkbox appears in tab; toggle it on; verify presence row; verify hypothetical shortcode with that category renders.
7. **Uninstall path**: purge honors the opt-in gate — if opt-in NOT set, `wp_acrossai_mcp_server_embed_transports` rows survive; if opt-in set, table dropped + `embeds_enabled` column preserved on server table (safer to leave the column than migrate on uninstall).
8. **CI green**: `embeds` PHPUnit suite green; all pre-existing suites regression-free.
9. **Grep gates**: all F037 delegation gates (list to be finalized in Phase 7) return zero hits.

---

## Not in scope

- **Block editor block** — planned as Phase 2 of F037 or as sibling F038. Same storage, same enable-gates, different renderer. Do NOT ship in F037 initial; would double the review surface.
- **REST endpoint for the toggles** — admin-only, saved via form POST + nonce; no REST API surface required.
- **Companion plugin integration (BuddyBoss add-on)** — separate repository; consumes F035 + F037 as its stable-ish upstream contracts.
- **Frontend UX polish (styling, animations, JS interactivity for master-toggle-reveals-subs)** — minimum viable: server-render with `disabled` attribute + basic JS toggle. Full UX pass deferred to a small follow-up feature.
- **Retroactive migration of existing OAuth-connected users to new embed permission model** — F037 introduces a new gate, but does not retroactively enforce it on existing users (opt-in per D21 fresh-install-only retirement pattern's opposite: additive default-OFF gate).

---

## Cross-References

- **F011** — BerlinDB Kern pattern (schema-drift reconciliation D28)
- **F013** — `AbstractServerTab` hierarchy + `ServerTabRegistry` (DEC-SERVER-TAB-CLASS-HIERARCHY)
- **F015** — Access control wrapper (`AcrossAI_MCP_Access_Control::user_has_server_access`)
- **F017** — Per-server ability presence-storage precedent (`wp_acrossai_mcp_server_abilities`)
- **F020** — Per-server tool presence-storage precedent (DEC-TOOL-SELECTION-PRESENCE-MODEL)
- **F021** — `ConnectorProfileRegistry` (canonical enumeration precedent)
- **F029** — D28 BerlinDB `$upgrades` reconciliation contract (schema-drift pattern)
- **F030** — DEV5 hand-rolled per-server-edit-tab form exception (3rd precedent — F037 is 4th, D13 escalation candidate)
- **F034** — `AbstractMCPClient` self-contained-subsystem-contract (D35 — F037's `AbstractEmbedTransport` mirrors this line-for-line)
- **F035** — `ConnectionMethodRegistry` (F037's DTO source; the whole reason this feature exists)
