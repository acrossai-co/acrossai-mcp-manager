# Phase 0 — Outline & Research

**Feature**: 025-server-tools-registration-hooks
**Status**: Complete — no `[NEEDS CLARIFICATION]` markers remain in the spec.

This file records the decisions taken across the three plan-clarification rounds on 2026-07-13 and the `/speckit-clarify` follow-up (Q1 observability, Q2 empty-state UX), plus the alternatives considered for each. The intent of a research phase is normally to resolve unknowns before design; this feature had no true unknowns by the time `/speckit-specify` ran, so each entry documents the decision path rather than a fresh investigation.

---

## Decision 1 — Storage model for the three protocol tools

**Decision**: Three `tinyint(1) NOT NULL DEFAULT 1` columns on `wp_acrossai_mcp_servers` (`tool_discover_abilities`, `tool_get_ability_info`, `tool_execute_ability`). Curated abilities stay in the F020 presence-based `wp_acrossai_mcp_server_tools` rows unchanged.

**Rationale**:
- Protocol set is fixed cardinality-3 with a well-known slug list; columns naturally match a fixed set.
- `DEFAULT 1` on the ALTER is the sole migration mechanism — MySQL fills existing rows during the schema update. No backfill helper.
- Reset semantics need to atomically flip all three back to enabled while touching zero curated rows — one `UPDATE` vs. three `DELETE`/`INSERT` round-trips.

**Alternatives considered**:
1. **Backfill helper seeding three rows into `wp_acrossai_mcp_server_tools` for every server on upgrade** — rejected. Requires new activation-hook step + idempotency handling; per-server writes; still leaves the "protocol tool with matching slug" edge case in the curated table.
2. **Union at runtime in the Controller only (no DB state for protocol tools, always emit them)** — rejected. Would make "operator removed a built-in default" impossible to persist; the spec's US2 requires per-server persistent state.
3. **New separate table `wp_acrossai_mcp_server_protocol_tools`** — rejected. Adds a schema surface and a query path for something that could be three columns.

**Source of decision**: user directive on 2026-07-13 during plan clarification round 3 ("add three columns for default tools in wp_acrossai_mcp_servers table where by default it will be 1 and if remove then make it 0").

**Reference**: soft-conflict with `DEC-TOOL-SELECTION-PRESENCE-MODEL` — see `memory-synthesis.md` §Conflict Warnings and `plan.md` §Complexity Tracking.

---

## Decision 2 — REST payload shape on the wire

**Decision**: Unified `tools` array on both GET and POST. `POST` accepts any mix of protocol slugs and curated slugs; the controller splits internally via `ToolPolicy::split_payload()`. `GET` composes the response via `ToolPolicy::compose_for_row()`.

**Rationale**:
- Preserves F020's REST contract byte-for-byte on the wire — no third-party API consumer needs to update.
- Client-side model stays simple: the Tools tab tracks one array of currently-added slugs; adds and removes append or splice.

**Alternatives considered**:
1. **Separate `protocol_flags` object + `curated_slugs` array** — rejected. Doubles the surface area and breaks the F020 wire compatibility.
2. **Nested payload with a `type` discriminator per entry** — rejected as overkill for a fixed set of three protocol slugs.

**Source of decision**: `docs/planings-tasks/023-server-tools-registration-hooks.md` §Design decisions ("REST payload stays unified") + user consent implicit via no objection during plan iteration.

---

## Decision 3 — Companion-plugin filter surface

**Decision**: Two hooks, two paths, non-overlapping.

- **Default server**: hook the existing vendor filter `mcp_adapter_default_server_config` (declared at `vendor/wordpress/mcp-adapter/includes/Servers/DefaultServerFactory.php:88`). Plugin's callback REPLACES `$config['tools']` with the composed set. No plugin-owned filter is emitted from this path.
- **Database server**: emit new plugin-owned filter `apply_filters( 'acrossai_mcp_manager_server_tools', string[] $tools, MCPServer\Row $server )` inside `Controller::register_database_servers()`.

**Rationale**:
- Single extension seam per path avoids double-firing.
- Vendor filter is already the documented default-server extension point; companion plugins targeting the default server hook it directly.
- Plugin-owned filter for database servers matches the F019 `acrossai_mcp_manager_server_tabs` naming convention and shape.
- Callbacks receive the composed set (columns union curated) so a companion plugin sees one uniform view — no need to know about the storage split.

**Alternatives considered**:
1. **Emit `acrossai_mcp_manager_server_tools` for both paths** — rejected. A companion plugin targeting only the default server would need to check `$server->server_slug` to gate; a companion targeting all servers would have to guard against double-modification when the vendor filter also fires on the same slug.
2. **Wrap the filter in try/catch** — rejected. Standard WordPress filter behavior is throw-propagates; companion authors own their throw safety. Documentation calls this out.

**Source of decision**: user directive on 2026-07-13 round 1 ("for the default server to pass any tools we are going to use the filter `mcp_adapter_default_server_config` and for the server that are register from our own plugin we are going to add a new filter").

---

## Decision 4 — Protocol-tool observability event routing *(Q1 from `/speckit-clarify`)*

**Decision**: Reuse the existing `acrossai_mcp_tools_changed` action (F020) for column flips. Fire one bullet per column that changed state on a POST save, with `operation` `'added'` (column `0` → `1`) or `'removed'` (column `1` → `0`) and `ability_slug` taken from `ToolPolicy::COLUMN_MAP`.

**Rationale**:
- Single unified event stream — existing F020 subscribers pick up protocol changes automatically.
- Matches F020's "one tool change = one event" philosophy.
- Zero new API surface for existing consumers.

**Alternatives considered**:
1. **New action `acrossai_mcp_server_protocol_tools_changed`** — rejected. Doubles the audit surface for no gain in most cases.
2. **Fire both** — rejected. Would duplicate every protocol-tool change on any subscriber that listens to both.
3. **No event** — rejected. Downstream audit tooling would have to diff column state itself.

**Source of decision**: user answer "A" to `/speckit-clarify` Q1 on 2026-07-13.

**Recorded**: spec §Clarifications; spec §FR-016.

---

## Decision 5 — Empty-tool-list UX *(Q2 from `/speckit-clarify`)*

**Decision**: Warning banner inside the empty "Added as tools" pane with copy "This server has no tools. AI clients can't discover or execute abilities. Click Reset to restore defaults." plus an inline Reset CTA. Server registration is NOT blocked in this state — the banner is informational, not preventive.

**Rationale**:
- Trusts the operator (removal remains allowed per US2).
- Surfaces the risk explicitly.
- Keeps recovery one click away by co-locating the Reset CTA.

**Alternatives considered**:
1. **Neutral empty-state** — rejected. Understates the operational risk.
2. **Auto-flag the server on the server list ("empty tools" badge)** — rejected as scope creep — would require touching the server list view outside the Tools tab.
3. **Prevent the state entirely** — rejected. Contradicts US2's "operator can remove built-in defaults" promise.

**Source of decision**: user answer "A" to `/speckit-clarify` Q2 on 2026-07-13.

**Recorded**: spec §Clarifications; spec §FR-017; spec §Edge Cases updated.

---

## Decision 6 — Migration mechanism

**Decision**: Bump `MCPServer\Table::$version` from `1.0.0` to `1.1.0`. BerlinDB's `maybe_upgrade()` compares the class property against the stored option (`acrossai_mcp_servers_db_version`) on next request-time boot and runs the `ALTER TABLE ADD COLUMN ... DEFAULT 1` via `dbDelta`. No separate activation-hook backfill step is added.

**Rationale**:
- Follows F011's canonical BerlinDB migration pattern.
- The `DEFAULT 1` on the ALTER is the sole guarantee that existing rows retain protocol-tool exposure — MySQL fills every existing row atomically during the schema update.
- Request-time boot is already wired via F011's `Main::bootstrap_database_tables()` — no new hook wiring.

**Alternatives considered**:
1. **Activation-hook backfill helper** — rejected. Duplicates what `DEFAULT` already guarantees; adds new class + tests.
2. **Backfill via a WP-CLI command operators must run manually** — rejected as F021's "fresh-install-only" pattern (`D21`) doesn't apply here — this feature will ship to live installs on the plugin baseline.

**Source of decision**: user directive on 2026-07-13 round 3; D21 confirmed non-applicable (no attestation of no-live-data).

---

## Retrieval Notes

- Sources consulted: `spec.md` (17 FRs, 4 stories, 6 SCs), `memory-synthesis.md`, `.specify/memory/constitution.md`, `docs/planings-tasks/023-server-tools-registration-hooks.md` (pre-drafted planning doc), `docs/memory/INDEX.md`.
- Not consulted (not needed): `docs/memory/DECISIONS.md` full body, `docs/memory/ARCHITECTURE.md` full body, `docs/memory/BUGS.md` full body — index summaries were sufficient per config `retrieval.full_memory_read_allowed: false`.
- Zero web research required — every decision was locally sourced from user directives + prior features' patterns.
