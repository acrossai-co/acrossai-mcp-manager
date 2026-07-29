# Memory Synthesis

## Current Scope

Feature 038 — `[acrossai_mcp_servers]` shortcode + reusable data-only base class `AbstractUserServersRenderer` under `public/Renderers/UserServers/`. Pure composition on F011 + F015 + F035 + F037; no new DB tables, no new REST endpoints, no new admin surface. Affected modules: `public/Renderers/UserServers/` (new), `includes/Main.php` (one-line wiring), `phpunit.xml.dist` + `.github/workflows/phpunit.yml` (new `user-servers` suite), `tests/phpunit/Public/Renderers/UserServers/` (new tests).

## Relevant Decisions

- **D35 / DEC-F034-SELF-CONTAINED-SUBSYSTEM-CONTRACT** (Status: Active, Source: DECISIONS.md) — Reason Included: F038 delegates transport enumeration to `AbstractEmbedTransport::get_all_registered_transports()` (never re-fires `acrossai_mcp_embed_transports`) and client metadata to `AbstractMCPClient::get_all_registered_clients()` via the F035 registry (never re-fires `acrossai_mcp_client_classes`). FR-005 + FR-023 encode this as grep-gates.
- **D36 / DEC-F035-PUBLIC-API-FINAL-CLASS-FILTER-ONLY-EXTENSION** (Status: Active, Source: DECISIONS.md) — Reason Included: `UserServersBlock` is a new `public/` `@experimental` class → MUST be `final`. `AbstractUserServersRenderer` is the sanctioned subclass-extension surface — this is the intentional inverse: extend the base by subclass; extend the block via filter only. The synthesis records this asymmetry explicitly for the planner.
- **DEC-CLIENT-RENDERER-PUBLIC-API** (Status: Active, Source: DECISIONS.md) — Reason Included: canonical `public/Renderers/` policy — `@experimental May change without notice before 1.0.0` docblock required on both new classes; shape freezes at 1.0.0. FR-022 encodes.
- **DEC-ACCESS-CONTROL-V2-ADOPTION** (Status: Active, Source: DECISIONS.md) — Reason Included: F038 consumes `AcrossAI_MCP_Access_Control::user_has_server_access( $user_id, $server_id )` (F015 wrapper) at every server gate. Fail-open when wpb-access-control package absent is the wrapper's contract, inherited transitively by F038 (Edge Case 1, FR-003).
- **D33 / DEC-OAUTH-AUTHORIZE-AC-GATE** (Status: Active, Source: DECISIONS.md) — Reason Included: precedent for a shared connection-time helper called from multiple non-MCP call sites. F038 is the fourth such call site (after OAuth authorize / CLI device-grant / Application Password). Same fail-open contract; same helper signature.

## Active Architecture Constraints

- **A1 — All hook registration in `Main.php`** (Source: ARCHITECTURE.md) — Reason: F038 wires exactly one action: `$this->loader->add_action( 'init', $user_servers_block, 'register_shortcode' )` inside `Main::define_public_hooks()`. Grep-gate: `add_shortcode` inside `public/Renderers/UserServers/` returns exactly one hit — the callback method body itself (transitivity per D17 applies to the `add_shortcode` call fired at `init`).
- **A2 — Singleton `instance()` pattern** (Source: ARCHITECTURE.md) — Reason: `UserServersBlock::instance()` required. Private constructor per S6. `AbstractUserServersRenderer` is abstract + stateless → no singleton on the base (companion plugins pick their own pattern).
- **A11 — Pure service-class singleton exemption** (Source: ARCHITECTURE.md) — Reason: `AbstractUserServersRenderer` is stateless (no ctor args, no instance state, no hook registration) — qualifies for A11's exemption. Companion plugins subclass and either singleton the child or leave it stateless.
- **A6 — Namespace `use` imports for `Includes` refs** (Source: ARCHITECTURE.md) — Reason: F038 lives in `AcrossAI_MCP_Manager\Public\Renderers\UserServers` and consumes `Includes\Database\MCPServer\Query`, `Includes\AccessControl\AcrossAI_MCP_Access_Control`, `Includes\Embeds\AbstractEmbedTransport`. All three need `use` imports, not bare relative names.
- **A19 — WP-canonical meta table** (Source: ARCHITECTURE.md) — Reason: F037's `_embeds_enabled` + `_embeds_clients` live in `wp_acrossai_mcp_servers_meta`. F038 MUST NOT read these keys directly (FR-024 grep-gate); all reads route through `AbstractEmbedTransport::is_enabled_for_server` to preserve the R2 memoization contract.

## Accepted Deviations

None apply. DEV1 (WP_List_Table), DEV3 (bidirectional imports), DEV5 (hand-rolled forms) are all admin-UI or CLI-auth carve-outs; F038 ships zero admin UI and zero forms.

## Relevant Security Constraints

- **S6 — Private singleton constructor** (Source: PROJECT_CONTEXT.md) — Reason: `UserServersBlock` MUST have `private function __construct()`; public ctor allows duplicate instantiation → the `$style_emitted` flag would fragment across instances → duplicate `<style>` emit + broken FR-016.
- **F038-internal FR-023 grep-gate** (Source: spec.md) — Reason: no `apply_filters( 'acrossai_mcp_embed_transports', … )` / `'acrossai_mcp_client_classes'` inside `public/Renderers/UserServers/`. Enforces D35 delegation contract at review-time.
- **F038-internal FR-024 grep-gate** (Source: spec.md) — Reason: no direct read of `_embeds_enabled` / `_embeds_clients` meta keys. Enforces gate cascade + R2 memoization preservation.

## Related Historical Lessons

- **B32 (F017/F026)** — Filter defaults MUST call the canonical resolver; NEVER re-derive from partial signals. Reason: F038's per-DTO gate MUST call `AbstractEmbedTransport::is_enabled_for_server` — even the master toggle check is subsumed by that call (its Gate 1). Re-reading `_embeds_enabled` from meta directly bypasses the R2 memoization + fail-open contract.
- **B15 / B26 — Grep-gate hygiene** — Reason: F038's grep-gates (FR-023..FR-025 + DoD) MUST use ERE with `\\?` to catch both `apply_filters('X')` and leading-backslash FQN forms; layer-scope must enumerate every subfolder under `public/Renderers/UserServers/` (currently one but likely to grow).
- **F037 worklog (2026-07-28)** — Reason: F038 depends on F037 shipping D38 `AbstractReactMountServerTab::register()` exception, F037's meta-table (A19), and F037's R2-memoized `is_enabled_for_server`. F038 is a fresh consumer, not an extender, of that stack.
- **F017 worklog (2026-07-07)** — Reason: DEC-ABILITY-OVERRIDE-RESOLUTION established the "single canonical resolver" principle F038 mirrors — never re-derive; always delegate.

## Conflict Warnings

None. F038's data-only base + filter-only extension for `UserServersBlock` cleanly composes D35 (subsystem contract) + D36 (final class + filter-only) + DEC-CLIENT-RENDERER-PUBLIC-API. The `AbstractUserServersRenderer` extension surface is subclass-based by design (data-only base ≠ `@experimental public` renderer); D36's `final` rule targets renderer classes, not extension-surface bases.

## Retrieval Notes

- Index entries considered: ~30 (all Active Decisions D33..D39, DEC-CLIENT-RENDERER-PUBLIC-API, DEC-ACCESS-CONTROL-V2-ADOPTION, A1/A2/A6/A11/A17/A18/A19, S1/S2/S6, B15/B24/B26/B32, worklog F017/F037).
- Source sections read: `docs/memory/INDEX.md` full file (196 lines).
- Full durable memory files (DECISIONS.md, ARCHITECTURE.md, BUGS.md, WORKLOG.md, PROJECT_CONTEXT.md) NOT opened — index sufficient. `full_memory_read_allowed: false` respected.
- Budget: 5/5 decisions, 5/5 architecture constraints, 0/3 deviations, 3/3 security, 4/(3 bug patterns + 2 worklog) = 4 lessons, ~810 words. Within `max_synthesis_words: 900`.
