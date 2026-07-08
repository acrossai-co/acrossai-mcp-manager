# Enforcement Contract — Feature 020

**Filter**: `mcp_adapter_pre_tool_call` (fired by `vendor/wordpress/mcp-adapter` at `Handlers/Tools/ToolsHandler.php:182` per D18)
**Class**: `AcrossAI_MCP_Manager\Includes\MCP\ToolExposureGate` (singleton)
**Method**: `filter_pre_tool_call( $result, string $tool_name, $mcp_tool, $server )` — docblock type of `$server` MUST be `\WP\MCP\Core\McpServer|mixed` (matches F017's `AbilityExposureGate.php:86` docblock exactly). Body MUST use duck-typed feature detection (`method_exists`), not `instanceof` — see step 2 below.
**Priority**: **30**
**Wired**: `includes/Main.php::define_public_hooks()` — mirror F017's `AbilityExposureGate` wiring line-for-line, adjusted for priority.

This is the F020 runtime enforcement path — closes SEC-020-001. Without this callback the Tools tab is UI theater. **This contract is a first-class requirement (FR-029), not an implementation detail.**

---

## Priority Slot Map

| Priority | Feature | Purpose | Reference |
|---------:|---------|---------|-----------|
|       10 | F015    | Access-control rule evaluation (WPBoilerplate) | INDEX row D18 |
|       20 | F017    | Per-server ability exposure toggle (`MCPServerAbility.is_exposed` + `meta.mcp.public` fallback) | F017 FR-030, plan.md:43 |
|   **30** | **F020**| **Per-server tool curation (row presence in `wp_acrossai_mcp_server_tools`)** | This contract |

Future features that add gates MUST occupy priority 40+ or explicitly re-plan the slot map with a memory-md capture.

---

## Callback Semantics (in evaluation order)

### 1. Deny-precedence check

```php
if ( is_wp_error( $result ) ) {
    return $result;
}
```

If any earlier priority (F015 access control, F017 ability exposure) has already returned a `WP_Error`, the deny survives. F020 NEVER re-allows an already-denied ability. Matches F017's shape verbatim.

### 2. Server resolution + fail-open (duck-typed feature detection)

**Ground truth on the `$server` argument**: mcp-adapter fires the filter at `vendor/wordpress/mcp-adapter/includes/Handlers/Tools/ToolsHandler.php:182` passing an `\WP\MCP\Core\McpServer` instance (verified at `vendor/wordpress/mcp-adapter/includes/Core/McpServer.php:26,260`). Its accessor is `get_server_id(): string` — it returns the server **slug** (e.g. `"mcp-adapter-default-server"`), NOT the integer database ID.

**F020 MUST use duck-typed feature detection** (`method_exists`), NOT `instanceof` against a specific class name. Feature detection is forward-compatible with vendor refactors and avoids the SEC-020-007 regression class (a single vendor namespace change silently fails-open the whole gate). Slug → integer id resolution is done via `MCPServerQuery` — the F011 servers table.

Canonical shape (mirrors F017's `AbilityExposureGate::gate_tool_call_by_exposure()` at `includes/MCP/AbilityExposureGate.php:98-119` line-for-line; mirrors F015's `AcrossAI_MCP_Access_Control::gate_mcp_tool_call()` at `includes/AccessControl/AcrossAI_MCP_Access_Control.php:249-253`):

```php
if ( ! is_object( $server ) || ! method_exists( $server, 'get_server_id' ) ) {
    return $result;
}

$server_slug = (string) $server->get_server_id();
if ( '' === $server_slug ) {
    return $result;
}

// Resolve slug → integer server_id via the F011 MCPServer table.
$rows = MCPServerQuery::instance()->query( array(
    'server_slug' => $server_slug,
    'number'      => 1,
) );
if ( empty( $rows ) ) {
    do_action( 'acrossai_mcp_tool_gate_missing_server', $tool_name, $server_slug );
    return $result;
}
$server_id = (int) $rows[0]->id;
```

If the `$server` object lacks `get_server_id()`, the accessor returns an empty string, or the slug doesn't resolve to a real MCP server row, F020 fails open — returns `$result` unchanged. Fires `acrossai_mcp_tool_gate_missing_server` for observability (D19 pattern; matches F017's `acrossai_mcp_ability_gate_missing_server`) ONLY when the resolution path failed for a non-empty slug — feature-absent cases (no `get_server_id`, empty string) are silent because they're the healthy synthetic-call / boot-time case.

**Rationale for fail-open + duck typing**: an unresolvable server context is usually a synthetic call from a testing tool, an adapter misconfiguration, or a race. Denying it would produce false positives; deferring to whatever the adapter's default is (either allow or a downstream filter's decision) is safer than injecting a spurious 403. Feature-detection avoids the class-name-drift trap where a vendor namespace refactor silently turns the whole enforcement gate into a no-op — the bug the SEC-020-007 audit found in the first-pass remediation.

**Do NOT** encode `$server instanceof \WP\MCP\Server` (wrong class) or `$server instanceof \WP\MCP\Core\McpServer` (right class but couples F020 to a specific vendor namespace). Do NOT call `$server->get_id()` (method does not exist on the vendor object). Do NOT cast `$server->get_server_id()` to `int` — the accessor returns a slug string, not an integer.

### 3. Protocol-tool bypass

```php
if ( in_array( $tool_name, self::EXCLUDED_SLUGS, true ) ) {
    return $result;
}
```

The three MCP-adapter protocol tools (`mcp-adapter/discover-abilities`, `mcp-adapter/get-ability-info`, `mcp-adapter/execute-ability`) are always callable — they are the protocol's discovery mechanism itself. Denying them would break MCP client bootstrapping.

`EXCLUDED_SLUGS` on `ToolExposureGate` mirrors the constant on `ToolsController` and the JS constant in `src/js/tools.js` — three-way duplication acknowledged in `plan.md §Complexity Tracking` per F017 precedent.

### 4. Presence lookup (with per-request cache)

```php
$added = self::get_added_slugs_cached( $server_id );
if ( in_array( $tool_name, $added, true ) ) {
    return $result;   // Allowed — defer to any later filter
}
```

`get_added_slugs_cached()` is a private static memoizer that calls `MCPServerToolQuery::instance()->get_added_slugs( $server_id )` at most once per (server, request). Cache is process-local (a static class property), invalidated only by process end. Cross-request state is not carried; each request builds its own cache from a fresh DB read.

**Cache invariant**: If `Query::replace_set()` runs later in the SAME request (e.g., a REST POST that immediately triggers a tool call — unusual but possible), the cache MUST be flushed before the gate runs on the tool call. Implementation: `ToolExposureGate::flush_cache( int $server_id )` — invoked from `ToolsController::post_tools()` after a successful `replace_set()` commit.

### 5. Absence-denial (the ONLY new deny path)

```php
return new \WP_Error(
    'acrossai_mcp_tool_not_added',
    __( 'This tool is not enabled on this MCP server.', 'acrossai-mcp-manager' ),
    array( 'status' => 403 )
);
```

If we reach this point, the ability is: (a) not already denied, (b) on a real server, (c) not a protocol tool, and (d) not in the operator-curated tools set. F020 injects the 403.

The error code `acrossai_mcp_tool_not_added` is stable public API — audit consumers and MCP client debuggers should be able to distinguish this from F015 access-control denials (`acrossai_mcp_access_denied`) and F017 exposure denials (`acrossai_mcp_ability_not_exposed`).

---

## Empty Tool Set Behavior

When `get_added_slugs( $server_id )` returns `[]`, every non-protocol tool call falls through to step 5 and returns 403. The mcp-adapter's `discover-abilities` still works (step 3 bypass), so the client can enumerate what's registered — but every attempt to invoke a real ability is denied.

**This is intentional** and matches the spec §User Story 1 Acceptance Scenario 4 + the zero-added warning banner: *"Connected AI clients won't be able to discover or execute any abilities on this server until you add at least one."*

The word "discover" in the banner is loose — the client CAN still discover via `mcp-adapter/discover-abilities` (a protocol tool) but every discovered ability is uncallable. This asymmetry is documented in the spec's Assumptions §"Enforcement is additive".

---

## List-Time Hiding (Deferred)

F020 does NOT filter the MCP `tools/list` endpoint. AI clients continue to see F017-allowed abilities in their discovery pass and only discover the F020 denial when they try to CALL a tool that isn't in the curated set.

**Rationale**: Call-time enforcement is the security boundary; list-time hiding is a UX polish. F017 also deferred list-time hiding as a follow-up feature. F020 matches this choice to keep the F020 scope tight; a future feature can add a `mcp_adapter_tools_list` filter callback that consults `get_added_slugs()` and hides unadded abilities from the discovery response.

**Consequence**: An AI client's initial `tools/list` may show more abilities than are actually callable. On call, unadded abilities return 403 with `acrossai_mcp_tool_not_added`. Clients that respect capability-flags MUST NOT retry.

---

## Test Coverage (SC-012)

`tests/phpunit/MCP/ToolExposureGateTest.php` MUST verify:

1. **Deny-precedence** — if `$result` is `WP_Error`, callback returns it unchanged even when the slug IS in the tools set.
2. **Fail-open on server without accessor** — pass a plain `stdClass` (no `get_server_id` method); callback returns `$result` unchanged; fires ZERO `acrossai_mcp_tool_gate_missing_server` actions (feature-absent is silent).
3. **Fail-open on empty slug** — pass a mock with `get_server_id()` returning `''`; callback returns `$result` unchanged; fires ZERO actions.
4. **Fail-open on missing server row** — pass a mock with `get_server_id()` returning a slug that doesn't resolve in `wp_acrossai_mcp_servers`; callback returns `$result` unchanged; fires EXACTLY ONE `acrossai_mcp_tool_gate_missing_server` action.
5. **Protocol-tool bypass** — the three excluded slugs pass through regardless of tools-set contents.
6. **Presence-allow** — a slug in the tools set returns `$result` unchanged.
7. **Absence-deny** — a slug NOT in the tools set returns `WP_Error( 'acrossai_mcp_tool_not_added', ..., [ 'status' => 403 ] )`.
8. **Empty-set deny-all** — with zero rows in `wp_acrossai_mcp_server_tools`, every non-protocol slug returns 403.
9. **Cache hit** — two calls with the same `$server_id` within one request cause exactly ONE `get_added_slugs()` DB query. Third call after `flush_cache()` causes a second query.
10. **SEC-020-007 regression guard** — pass a mock whose class name matches `\WP\MCP\Server` (the wrong-name Regression pattern); callback MUST still route through `method_exists` and produce the correct semantics based on whether `get_server_id` exists, NOT reject based on class name. This is the anti-regression test.

---

## Requirements Traceability

| Contract element                          | Requirement |
|-------------------------------------------|-------------|
| `mcp_adapter_pre_tool_call` priority 30   | FR-029 |
| Deny-precedence                            | FR-029 (a) |
| Fail-open on missing server               | FR-029 (b), D19 |
| Protocol-tool bypass                       | FR-029 (c), FR-025 |
| Presence check via `get_added_slugs`      | FR-029 (d) |
| `WP_Error` code `acrossai_mcp_tool_not_added` | FR-029 |
| Per-request cache + flush on save         | Perf / SC-009 |
| List-time hiding deferred                  | Spec §Assumptions §"Enforcement is additive" |
| Test coverage 1..7                         | SC-012 |
