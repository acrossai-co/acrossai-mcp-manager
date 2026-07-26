# Memory Synthesis

## Current Scope

F034 — MCP Client Metadata + Filter-Aware Enumeration Refactor. Adds six metadata methods (`get_icon`, `get_description`, `get_config_file`, `get_top_level_key`, `get_instructions`, `get_priority`) to `AbstractMCPClient` with backwards-compatible defaults. Migrates `MCPClientsBlock::CLIENT_META` verbatim into per-client overrides. Adds canonical enumeration `AbstractMCPClient::get_all_registered_clients()` mirroring `ConnectorProfileRegistry::get_profiles()`. Deletes the glob-based `get_all_clients()`. Priority-based ordering (default 100, built-ins pre-assigned 10-80) preserves byte-identical sub-nav render. Affected modules: `includes/MCPClients/`, `public/Renderers/MCPClientsBlock.php`, `tests/phpunit/MCPClients/`, `tests/phpunit/Public/Renderers/`, `docs/memory/{DECISIONS,INDEX}.md`, `README.txt`.

## Relevant Decisions

- **DEC-CLIENT-RENDERER-PUBLIC-API** (Reason Included: `MCPClientsBlock` lives in `public/Renderers/` under this decision's `@experimental` policy; F034 preserves its public API (`instance()`, `slug()`) and stays inside the contract, Status: Active — F013/F016, Source: DECISIONS.md)
- **D24 / DEC-F026-ADVERTISEMENT-VS-CALL-TIME-DEFENSE-IN-DEPTH** (Reason Included: not a direct enforcement dependency, but the "one canonical enumeration path, never partial derivation" principle transfers directly to F034's `get_all_registered_clients()`, Status: Active — F026 v3, Source: DECISIONS.md)

## Active Architecture Constraints

- **A1 — Hook registration in Main.php only** (Reason Included: F034 fires `apply_filters('acrossai_mcp_client_classes', ...)` inside `get_all_registered_clients()` but does NOT register the filter via `add_filter` — no wiring change; A1 preserved, Source: ARCHITECTURE.md)
- **A2 — Singleton pattern for feature classes** (Reason Included: `MCPClientsBlock::instance()` singleton preserved verbatim; F034 does not touch singleton mechanics, Source: ARCHITECTURE.md)
- **A11 — Pure service class exemption from A2** (Reason Included: `AbstractMCPClient` IS an A11 pure service (stateless, no ctor args, no hook registration per FR-008/FR-009 in its class docblock); all six new methods added by F034 are pure (no state, no side effects except firing the existing filter); A11 exemption preserved, Source: ARCHITECTURE.md)

## Accepted Deviations

- None applicable to F034. The client subsystem does not intersect DEV1 (`WP_List_Table` on the parent menu), DEV5 (hand-rolled per-server-edit tab HTML), or other listed deviations.

## Relevant Security Constraints

- **SEC-013-008 — Invalid FQNs silently skipped in the `acrossai_mcp_client_classes` filter loop** (Reason Included: FR-007 explicitly preserves this semantic — invalid FQNs must not fatal a request; the new canonical enumeration inherits this exact behaviour from the pre-refactor `MCPClientsBlock::render_body()`, Source: security-constraints.md)

## Related Historical Lessons

- **F017 (Per-server ability selection) established the `ConnectorProfileRegistry`-like pattern for AI connectors** (Reason Included: F034 mirrors this pattern for MCP clients line-for-line — `ConnectorProfileRegistry::get_profiles()` at `includes/Connectors/ConnectorProfileRegistry.php:57-118` is the canonical shape F034's `get_all_registered_clients()` reproduces, adapted for FQN-string vs. instance contribution style)
- **F030 Bug Pattern B35 — filter-priority slot map for `wp_register_ability_args`** (Reason Included: F034 introduces a NEW priority-ordering seam via `get_priority(): int` — WP-idiomatic default 100 pattern — for the client subsystem. Related to B35's principle of documenting priority slots explicitly, but distinct: B35 is about filter-registration priority across plugins, F034's `get_priority()` is per-client sub-nav slot preference. No conflict; establishing a symmetric convention.)

## Conflict Warnings

- **None.** F034 aligns with all applicable active constraints (A1, A2, A11) and directly implements the principle behind Bug Pattern B32 ("Filter defaults MUST express the plugin's canonical semantic; never partial derivation") — the whole refactor establishes ONE canonical enumeration path for MCP clients where three previously disagreed.

- **Soft note (not blocking)**: The client subsystem currently has no `DEC-MCP-CLIENT-*` family in INDEX.md — F034 will establish it via the memory-hygiene task in the spec (`/speckit-memory-md-capture` post-implementation) capturing the "self-contained subsystem contract" pattern. This is a memory addition, not a conflict.

## Retrieval Notes

- **Index entries considered**: ~20 (D-series 1-34, B-series 22-39, A-series 1-17, DEV-series 1-5, security constraints tag-search). Scoped to entries tagged `mcp-client`, `filter`, `abilities-api`, `f017`, `f021`, `f026`, `public-api`, `singleton`, `pure-service`, `sec-013-008`, `canonical-resolver`.
- **Source sections read**: None beyond `docs/memory/INDEX.md` (already loaded earlier in session) and the row-descriptions therein. Per config `full_memory_read_allowed: false`, no full memory file dumps performed.
- **Feature memory** (`specs/034-mcp-client-metadata-refactor/memory.md`): does not exist. First planning pass for this feature.
- **Budget status**: 3/5 decisions, 3/5 architecture constraints, 0/3 accepted deviations, 1/3 security constraints, 2/3 bug patterns (B32 and B35 selected; B23 considered and rejected as low-relevance), 0/2 worklog items. Well under all retrieval caps. Synthesis word count under 900.
- **Phase**: Plan (spec is stable; user has invoked `/speckit-memory-md-plan-with-memory` as the pre-plan gate). Retrieval prioritised boundary definitions, module ownership (public/Renderers/ vs. includes/MCPClients/), and architectural drift risks (canonical-vs-derived enumeration).
