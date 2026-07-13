# Security Constraints — Feature 025

**Source reviews**:
- [Plan v1 (2026-07-13)](../../docs/security-reviews/2026-07-13-025-server-tools-registration-hooks-plan.md) — 3 INFO findings.
- [Plan v2 (2026-07-13)](../../docs/security-reviews/2026-07-13-025-server-tools-registration-hooks-plan-v2.md) — 1 LOW + 2 INFO findings.
- [Tasks (2026-07-14)](../../docs/security-reviews/2026-07-14-025-server-tools-registration-hooks-tasks.md) — 3 INFO task-hygiene findings, all applied inline to `tasks.md`.

**Aggregate risk**: LOW.

## Boundaries preserved

- **REST auth boundary**: `permission_callback = manage_options` on both GET and POST at `/wp-json/acrossai-mcp-manager/v1/servers/{id}/tools`. No new routes; F020 baseline retained.
- **DB write boundary**: All new writes go through BerlinDB prepared paths (`MCPServerQuery::update_item()`, `MCPServerToolQuery::replace_set()`). No raw SQL added.
- **Vendor boundary**: `vendor/wordpress/mcp-adapter/` is untouched. The plugin consumes the vendor's `mcp_adapter_default_server_config` filter without modification.
- **Input sanitization boundary**: three `tinyint` flags sanitized via `absint()` at the REST boundary; slugs `strval`'d and validated against `wp_get_abilities()` per F020.
- **Filter-return boundary**: `array_values( array_unique( array_map( 'strval', (array) $return ) ) )` defensive normalization sits between third-party filter callbacks and `$adapter->create_server()`.

## Implementation constraints derived from findings

Every finding is INFORMATIONAL or LOW; none block implementation. All are folded into concrete task IDs in `tasks.md`:

### Plan-review v1 (3 INFO)

| Finding | Task ID | Constraint |
|---|---|---|
| SEC-025-INFO-1 (confused-deputy on filter override) | T018 | Add filter-authors advisory sentence in `docs/extending-server-tools.md` noting that a callback re-adding a protocol slug silently overrides the operator's UI-facing removal decision. |
| SEC-025-INFO-2 (two-write POST race) | T012 | Add `// SEC-025-INFO-2: accepted race window ...` code comment between the `MCPServerQuery::update_item()` and `MCPServerToolQuery::replace_set()` calls in `post_tools()`. |
| SEC-025-INFO-3 (`EXCLUDED_SLUGS` vestigial) | T019 | Add docblock to `ToolExposureGate::EXCLUDED_SLUGS` marking it "vestigial post-F025; safety net for cached AI clients; NOT a precedent for new bypasses". |

### Plan-review v2 (1 LOW + 2 INFO)

| Finding | Task ID | Constraint |
|---|---|---|
| SEC-025-v2-1 (LOW; spec-vs-plan empty-set inconsistency for default server) | T020 | Amend `spec.md` §Edge Cases to distinguish DB-server vs default-server behavior on empty composed set; Option A (documentation-only). |
| SEC-025-v2-2 (**CORRECTED 2026-07-14 — runtime evidence disproved the v2 finding**) | T012 + T016 | Original v2 finding claimed `wp_abilities_api_init` fires before `rest_api_init` so `wp_get_abilities()` resolves protocol slugs at POST time. Runtime evidence disproved this — the vendor's abilities-registration listener attaches inside `Controller::initialize_adapter()` on `rest_api_init`, which fires AFTER `wp_abilities_api_init`. Implementation shipped a validation bypass: protocol slugs skip `wp_get_abilities()` catalog resolution entirely (`ToolPolicy::PROTOCOL_TOOLS` is authoritative). Companion GET fix: `ToolsController::get_tools()` merges `ToolPolicy::PROTOCOL_TOOL_METADATA` into the abilities catalog with dedup. Documented as FR-018. T016 test case retained and now asserts the bypass (POST with only protocol slugs → 200 regardless of Abilities-API bootstrap timing). |
| SEC-025-v2-3 (INFO; `server_slug` KEY-not-UNIQUE) | T009 | Add code comment on `MCPServerQuery::query()` call inside `filter_default_server_config()` about KEY-not-UNIQUE precedence. |

### Tasks-review (3 INFO) — inline task edits applied 2026-07-14

| Finding | Task ID | Constraint | Status |
|---|---|---|---|
| SEC-TASKS-025-1 (missing confused-deputy PHPUnit assertion) | T011 | Add 9th test case: filter can re-add a protocol slug the operator removed via column=0; assert `acrossai_mcp_tools_changed` is NOT fired on filter-side changes. | **Applied inline** to T011 body. |
| SEC-TASKS-025-2 (missing truly-empty tools array test) | T016 | Add `test_post_empty_tools_array_produces_empty_composed_set()` — POST `{ tools: [] }` → 200, all three `tool_*` columns = 0, curated table empty, GET returns `{ tools: [] }`. | **Applied inline** to T016 body. |
| SEC-TASKS-025-3 (missing `permission_callback` preservation affirmation) | T012 | Add explicit PRESERVATION invariant sentence: MUST NOT modify `register_rest_route()` `permission_callback` binding, nonce middleware setup, or `manage_options` capability check. | **Applied inline** to T012 body. |

## Constitutional §III mapping

| §III item | F025 stance |
|---|---|
| Input sanitized at boundary | `absint()` on three flags; `strval` + `wp_get_abilities()` validation on slugs. |
| Output escaped at render | Dialog copy via `__()` + React-side escaping; no new PHP HTML output. |
| Nonces on forms/AJAX | Inherited from F020 (WP core REST middleware). |
| Capability checks on admin/mutating | `manage_options` on both REST routes; unchanged. |
| Prepared SQL | BerlinDB Kern (`update_item`, `replace_set`) prepares under the hood. |
| REST `permission_callback` explicit | Preserved on both routes. No `__return_true` added. |
| Hashed secrets | N/A — F025 has no secret surface. |
| File uploads | N/A. |

## Not blocking implementation

Proceed directly to `/speckit-architecture-guard-violation-detection` (Step 5 of the parent governed-plan workflow). The three INFO findings are inline TODOs, not gates.
