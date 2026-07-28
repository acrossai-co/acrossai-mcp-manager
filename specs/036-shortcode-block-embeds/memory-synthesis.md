# Memory Synthesis

## Current Scope

F037 — Add per-server "Embeds" admin tab under `admin/Partials/ServerTabs/EmbedsTab.php` (extends `AbstractServerTab` from F013 hierarchy) with master toggle + per-transport sub-toggles. Persist via new BerlinDB junction table `wp_acrossai_mcp_server_embed_transports` (presence model) + new `embeds_enabled TINYINT(1)` column on `wp_acrossai_mcp_servers` (D28 3-part contract). Ship `AbstractEmbedTransport` base class under `includes/Embeds/` + 3 built-in concretes (`NpmEmbedTransport`, `ClientEmbedTransport`, `AiConnectorEmbedTransport` — singular keys per Q1). Filter `acrossai_mcp_embed_transports` for third-party extension. New `[acrossai_mcp_embed]` shortcode under `public/Renderers/EmbedBlock/` gated by master + per-transport + F015 access control cascade. Two observability actions per Q3 (`acrossai_mcp_embed_master_toggled`, `acrossai_mcp_embed_transport_toggled`). Optional GC helper per Q2. Consumes `ConnectionMethodRegistry::get_all()` (F035) as DTO source.

## Relevant Decisions

- **D35 / DEC-F034-SELF-CONTAINED-SUBSYSTEM-CONTRACT** — F037's `AbstractEmbedTransport::get_all_registered_transports()` mirrors F034's `AbstractMCPClient::get_all_registered_clients()` line-for-line (FR-008: filter fire → FQN validate → key regex → dedup + `_doing_it_wrong` under `WP_DEBUG` → sort by (priority ASC, key ASC) → `array_values`). (Reason Included: F037 IS the direct application of the pattern to a fourth subsystem; Status: Active; Source: DECISIONS.md)
- **D36 / DEC-F035-PUBLIC-API-FINAL-CLASS-FILTER-ONLY-EXTENSION** — F037's `EmbedBlockRenderer` under `public/Renderers/` MUST be `final class` per this policy (extension via `acrossai_mcp_embed_render_html` filter, not subclass). FR-012 also mandates `final` on every `AbstractEmbedTransport` subclass. (Reason Included: F037 introduces a new `public/` `@experimental` class; policy applies; Status: Active; Source: DECISIONS.md)
- **DEC-SERVER-TAB-CLASS-HIERARCHY (F013)** — `EmbedsTab` extends `AbstractServerTab`, registers via `ServerTabRegistry::instance()->register()`. Template-method pattern owned by the base class. (Reason Included: FR-001 declares this extension shape; Status: Active; Source: DECISIONS.md)
- **DEC-TOOL-SELECTION-PRESENCE-MODEL** — junction table `wp_acrossai_mcp_server_embed_transports` with `UNIQUE(server_id, transport_key)` presence-model: row exists ⇔ enabled. FR-005. F017 first use (boolean-with-fallback carve-out); F020 second use (pure presence); F037 is third use. (Reason Included: FR-005 storage shape codifies this; Status: Active; Source: DECISIONS.md)
- **D28 / DEC-BERLINDB-SCHEMA-DRIFT-RECONCILIATION** — `embeds_enabled` column addition on `wp_acrossai_mcp_servers` requires 3-part contract: (1) bump `$version` 1.1.1 → 1.1.2, (2) register `$upgrades = ['1.1.2' => 'upgrade_to_1_1_2']` callback with idempotent `INFORMATION_SCHEMA` existence check + `ALTER TABLE ADD COLUMN`, (3) register schema reconciliation on `admin_init@3` via `Main::reconcile_database_schemas()`. FR-016. (Reason Included: FR-016 explicitly cites this; Status: Active; Source: DECISIONS.md)

## Active Architecture Constraints

- **A1** — all hook registration in `Main.php`. F037 wires `add_shortcode( 'acrossai_mcp_embed', … )` in `define_public_hooks()`, tab registration in `define_admin_hooks()`, schema reconciliation callback in `define_admin_hooks()`. Zero `add_*` calls inside `includes/Embeds/`, `admin/Partials/ServerTabs/EmbedsTab.php`, or `public/Renderers/EmbedBlock/`. (Reason Included: SC-005 grep gate enforces this; Source: ARCHITECTURE.md)
- **A2** — singleton pattern. `EmbedsTab` (via `AbstractServerTab`), `EmbedBlockRenderer` (per D36 `final class`), and `AbstractEmbedTransport` subclasses (via base-class-provided `instance()` if needed for state, or A11 exemption if pure). (Reason Included: standard plugin convention; Source: ARCHITECTURE.md)
- **A6** — cross-namespace references from `Includes\Embeds` to `Includes\Database\ServerEmbedTransports` + `Includes\Database\MCPServer` require explicit `use` imports (bare relative names silently fail per B1). (Reason Included: F037's canonical enumeration + DB access spans namespaces; Source: ARCHITECTURE.md)
- **A18** — WP function stubs pattern for pure-PHP suites. F037 uses `tests/bootstrap-wp.php` NOT stubs — FR-015 confirms. Transitive deps (BerlinDB, `home_url()`, `get_option()`, shortcode API, F015 wrapper) exceed the ~10-symbol stub ceiling. (Reason Included: F037 test-bootstrap-choice rationale; Source: ARCHITECTURE.md)
- **DEC-BERLINDB-TABLE-REQUEST-BOOT** — new `ServerEmbedTransports\Table` MUST be instantiated at request time via `Main::load_hooks()` per FR-016. Activation-time-only instantiation leaves BerlinDB's DB interface empty on subsequent requests. (Reason Included: any new BerlinDB Table subclass requires this; Source: DECISIONS.md)

## Accepted Deviations

- **DEV5 NO LONGER APPLIES** (post-Q4 pivot 2026-07-27) — F037 pivoted to full React with REST-based save per D37 codification; DEV5 hand-rolled form exception no longer needed. Consumer count returns to 3 (F013 Update Server + F013 Danger Zone + F030 Access Control override); D13 escalation candidate retracted. See spec Clarifications Q4 + plan §Constitution Check IV.

## New Governing Decision (proposed post-pivot, awaiting user capture approval)

- **D37 / DEC-ADMIN-UI-REACT-FIRST** — Any new admin UI with interactive multi-field state MUST use React with sanctioned `@wordpress/*` packages (matches F017 Abilities + F020 Tools + F037 Embeds). If React is genuinely inappropriate (RARE — e.g., WP-CLI dashboards, activation notices), fall back to vanilla WP admin PHP + core JS + `wp-ajax` (matching the internal shape of how existing REST endpoints work). NEVER use hand-rolled admin forms + inline vanilla JS as a substitute for React — proven not to scale by F037's mid-flight pivot after sub-toggle sync bug. DEV5 (hand-rolled form) exception NARROWED to read-only or single-submit surfaces only. Applies codebase-wide going forward; F037 is the third canonical implementation of the F017/F020 pattern for per-server admin UIs. Consequence for future features: any interactive multi-field admin surface plan MUST have a React section in its plan.md; any admin surface introducing hand-rolled forms must justify in plan Constitution Check §IV why it falls under narrowed-DEV5 rather than D37.

## Relevant Security Constraints

- **S1** — save handler MUST verify nonce via `wp_verify_nonce()`. FR-018 explicit. (Reason Included: admin form submit; Source: CONSTITUTION.md §III)
- **S6** — singleton `__construct()` MUST be private. `EmbedsTab` inherits from `AbstractServerTab` (already private); `EmbedBlockRenderer` MUST declare `private __construct()` per D36 `final class`. (Reason Included: prevents double-instantiation → double hook registration; Source: PROJECT_CONTEXT.md)
- **S9 not applicable** — no consent surface. F037 admin gate is `manage_options`-scoped; frontend shortcode is public read-only display.

## Related Historical Lessons

- **B18** — `$wpdb` returns TINYINT columns as string; `1 === $row->col` always false. F037's `is_enabled_for_server()` (FR-009) reads `wp_acrossai_mcp_servers.embeds_enabled` AND `wp_acrossai_mcp_server_embed_transports.is_enabled`; both TINYINT(1). MUST cast to `(int)` before strict compare OR use `! empty()` for boolean semantics. Plan-phase test coverage MUST include a scenario asserting the boolean helper returns correctly with actual `$wpdb` string-returning rows (not just mock-object rows).
- **B21** — BerlinDB v3 `modified` flag (NOT `date_updated`). FR-005 spec says `date_modified DATETIME` — column definition MUST use `'flags' => ['modified']` (auto-update-on-write). Grep gate for new BerlinDB Schemas: `grep -rn "'date_updated'" includes/Database/` MUST return zero matches.
- **B34** — silent write-loss when BerlinDB schema drifts from Schema.php AND db_version option matches code. F037 adds one column + one new table — BOTH require D28 3-part contract. FR-016 codifies compliance. Sanity SQL for post-release audit: `SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME LIKE 'wp_acrossai_mcp_server%'`.

## Conflict Warnings

None. All three Q&A clarifications (Q1 singular keys, Q2 persist-silently + GC helper, Q3 two granular observability actions) align cleanly with D35, D36, DEC-TOOL-SELECTION-PRESENCE-MODEL, D28, DEV5, and every relevant bug pattern. No hard or soft conflicts detected.

## Retrieval Notes

- Index entries considered: 20 (targeted read of §Active Decisions D28, D35, D36, DEC-SERVER-TAB-CLASS-HIERARCHY, DEC-TOOL-SELECTION-PRESENCE-MODEL, DEC-BERLINDB-TABLE-REQUEST-BOOT; §Architecture Constraints A1, A2, A6, A11, A18; §Bug Patterns B1, B18, B21, B26, B32, B34; §Accepted Deviations DEV5; §Security Constraints S1, S6, S9; §Worklog F017/F020/F030/F032 for precedent context).
- Source sections read: `docs/memory/INDEX.md` (already cached from F035 session; no fresh full read needed). No source-body reads required — every relevant entry's INDEX row carries enough detail for planning.
- Budget status: 5/5 decisions, 5/5 constraints, 1/3 deviations, 2/3 security (S9 flagged as N/A + noted), 3/3 bug patterns, 0/2 worklog entries cited (F030 test-infrastructure + F032 D28 precedent implicit in the decisions above). All within caps.
- Optimizer: DISABLED (`optimizer.enabled: false`) — markdown-only index-first flow used.
- Word count: ~880 / 900 max target.
