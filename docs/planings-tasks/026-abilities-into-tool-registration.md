# Planning: Include F017-effective abilities in the composed tool list at server-registration time (Feature 026)

F025 (`specs/025-server-tools-registration-hooks/`, PR #28, merged 2026-07-14) shipped `ToolPolicy::compose_for_row( MCPServer\Row ): string[]` which returns `(enabled protocol columns) ∪ (curated slugs from wp_acrossai_mcp_server_tools)`. Three call sites consume it: `Controller::register_database_servers()` at `includes/MCP/Controller.php:142`, `Controller::filter_default_server_config()` at `includes/MCP/Controller.php:247`, and `ToolsController::get_tools()` at `includes/REST/ToolsController.php:201`.

Meanwhile, F017 (`wp_acrossai_mcp_server_abilities` + `ExposureResolver` + `AbilityExposureGate` at `mcp_adapter_pre_tool_call` priority 20) has been silently doing per-server ability exposure enforcement at **call-time only**. The Abilities tab at `?tab=abilities` lets the operator toggle each ability's per-server visibility (row in table), falling back to the global `mcp.public` metadata when no override exists — but this state never made it into what `tools/list` advertises. Result: an operator marks an ability visible in the Abilities tab, but the ability doesn't appear in the server's `tools/list` because it wasn't explicitly added via the Tools tab. Only F020's curated set and F025's protocol columns did.

Feature 026 closes the gap: at server-registration time, iterate every registered WordPress ability and use `ExposureResolver::resolve( $server_id, $slug, $meta )` to determine per-server effective exposure. Include every ability that resolves to `true` in the composed tool list passed to `\WP\MCP\Core\McpAdapter::create_server()`. The REST GET `/tools` stays on `compose_for_row()` — the Tools tab UI still shows "what the operator explicitly picked", separate from the F017 Abilities tab's per-server visibility model.

The design mirrors F025's shape: a new stateless helper `ToolPolicy::compose_effective_tools_for_row( Row ): string[]` alongside `compose_for_row()`, called by both server-registration paths (default via `mcp_adapter_default_server_config` vendor filter; DB via `Controller::register_database_servers()`). The existing F025 filter `acrossai_mcp_manager_server_tools` is REUSED — its pre-filter composed list widens from `(protocol + curated)` to `(protocol + curated + F017-effective abilities)`. Same signature, wider input, no new hook surface (confirmed with user 2026-07-14: "reuse the F025 filter").

The change is **fully backwards-compatible** with F025 and F017:

- F025's `ToolPolicy::compose_for_row()` is preserved verbatim; only a new sibling method is added.
- F017's `ExposureResolver`, `AbilityExposureGate`, `wp_acrossai_mcp_server_abilities` table, `MCPServerAbility\Query`, and REST `AbilitiesController` are untouched. Call-time enforcement (priority 20) is unchanged.
- The Tools tab REST GET response shape is unchanged — F017-effective abilities do NOT appear there. Only the two server-registration call sites (`Controller` x2) switch to the new composer.
- Companion plugins hooking `acrossai_mcp_manager_server_tools` (F025 filter) continue to receive the same signature; the pre-filter list is a strict superset of what they saw before.

The fail-open pattern (D19) from F017's `AbilityExposureGate` carries over: if `wp_get_abilities` / `wp_get_ability` are unavailable at registration time, the F017 pass is skipped and the server still registers with protocol + curated. Matches F025's `filter_default_server_config()` defensive short-circuits.

---

## Speckit Workflow

```markdown
# 1. Branch
/speckit.git.feature "abilities-into-tool-registration"

# 2. Specify
/speckit.specify "Add a new stateless helper
\\AcrossAI_MCP_Manager\\Includes\\Database\\MCPServer\\ToolPolicy::compose_effective_tools_for_row(
Row \$row ): string[] that returns the union of (a) F025's existing
compose_for_row( \$row ) output — enabled protocol columns + curated slugs
from wp_acrossai_mcp_server_tools — and (b) every ability where
\\AcrossAI_MCP_Manager\\Includes\\Database\\MCPServerAbility\\ExposureResolver::resolve(
(int) \$row->id, \$ability->get_name(), \$ability->get_meta() ) === true.
Iterate abilities via wp_get_abilities() when function_exists('wp_get_abilities');
fail-open (skip F017 pass, return compose_for_row output) when the Abilities
API is unavailable. Dedup + array_values + strval-normalize the result.

Switch both F025 server-registration call sites to the new method:
- includes/MCP/Controller.php:142 (register_database_servers) —
  ToolPolicy::compose_for_row(\$server) → ToolPolicy::compose_effective_tools_for_row(\$server).
- includes/MCP/Controller.php:247 (filter_default_server_config) —
  ToolPolicy::compose_for_row(\$rows[0]) → ToolPolicy::compose_effective_tools_for_row(\$rows[0]).

Do NOT touch the third F025 call site (includes/REST/ToolsController.php:201
in get_tools) — the REST GET stays on compose_for_row(). The Tools tab UI
reflects the operator's explicit picks; the Abilities tab reflects visibility
overrides; both remain distinct mental models per operator UX.

Reuse the F025 filter acrossai_mcp_manager_server_tools — its pre-filter
composed list widens to (protocol + curated + F017-effective abilities).
Update the filter's docblock at includes/MCP/Controller.php line ~155 to
describe the extended composition. Update docs/extending-server-tools.md §
Filter contract and § Arguments to reflect the wider input. Add a new §
Interaction with the Abilities tab section noting that toggling an ability
off in the Abilities tab (is_exposed = 0 row) removes it from tools/list on
the next request, even if it has mcp.public = true.

Add PHPUnit coverage: four new cases in
tests/phpunit/Database/MCPServer/ToolPolicyTest.php exercising
compose_effective_tools_for_row (public-no-override-included,
public-disabled-override-excluded, non-public-enabled-override-included,
non-public-no-override-excluded); one new case in
tests/phpunit/MCP/ControllerToolsInjectionTest.php asserting
register_database_servers produces the F017-widened composed set when
abilities are registered. Reset ExposureResolver's per-request cache in
setUp() via ExposureResolver::_reset_cache_for_tests().

Do NOT modify F017's storage (wp_acrossai_mcp_server_abilities schema,
MCPServerAbility\\Query, MCPServerAbility\\Row, MCPServerAbility\\Schema),
ExposureResolver, or AbilityExposureGate. Do NOT modify AbilitiesController
REST routes or src/js/abilities.js. Do NOT modify ToolsController — the
REST GET semantic is preserved verbatim. Do NOT edit any file under
vendor/wordpress/mcp-adapter/. No schema changes. No React changes to
src/js/tools.js. No changes to src/js/abilities.js."
```

### Detailed Description for `/speckit.specify`

> **Before writing a single line of code, read and internalize all six of
> these governing documents in full:**
>
> 1. `AGENTS.md` — this plugin's singleton pattern, the "hook registration
>    lives ONLY in `includes/Main.php` via `$this->loader`" rule, and the
>    Before Commit Checklist.
> 2. `docs/planings-tasks/023-server-tools-registration-hooks.md` — the
>    F025 planning doc that shipped `ToolPolicy::compose_for_row()` and the
>    `acrossai_mcp_manager_server_tools` filter. F026 SUPPLEMENTS F025 by
>    adding a new sibling composer method and switching two call sites;
>    F025's filter, storage layers, and REST GET shape all continue
>    unchanged.
> 3. `docs/planings-tasks/017-per-server-ability-selection.md` — the F017
>    planning doc that shipped `wp_acrossai_mcp_server_abilities`,
>    `MCPServerAbility\Query::upsert()`, `ExposureResolver::resolve()`
>    (single-source-of-truth per `DEC-ABILITY-OVERRIDE-RESOLUTION`), and
>    `AbilityExposureGate` at `mcp_adapter_pre_tool_call` priority 20.
>    F026 REUSES `ExposureResolver::resolve()` verbatim as its
>    canonical decision-maker for effective per-server exposure.
> 4. `includes/Database/MCPServer/ToolPolicy.php` (F025 shipping code) —
>    read `PROTOCOL_TOOLS`, `COLUMN_MAP`, `PROTOCOL_TOOL_METADATA`,
>    `compose_for_row()`, and `split_payload()`. F026's new method
>    `compose_effective_tools_for_row()` sits immediately after
>    `compose_for_row()` and calls it internally to seed the union.
> 5. `includes/Database/MCPServerAbility/ExposureResolver.php` (F017
>    shipping code) — read `resolve( int, string, array ): bool`, its
>    per-request static cache at line 40, and the fallback precedence
>    (row-in-table beats `meta[mcp][public]`) at lines 67–72.
> 6. `docs/memory/INDEX.md` §Active Decisions — read
>    `DEC-ABILITY-OVERRIDE-RESOLUTION` (F017 single-resolver pattern),
>    `DEC-F025-HYBRID-TOOL-STORAGE-PROTOCOL-VS-CURATED` (F025 composer
>    lineage), and `B29` (vendor `add_action`-inside-`__construct`
>    hook-timing race — F025 mitigations already in place at
>    `ToolPolicy::PROTOCOL_TOOL_METADATA` + `ToolsController::post_tools`
>    validation bypass; F026 does not need to re-solve this).
>
> Every decision — whether to overload `compose_for_row()` or add a
> sibling method, whether to reuse the F025 filter or introduce a new
> hook, whether to also extend the REST GET response — must be justified
> against the above. Defaults (confirmed with user 2026-07-14 during plan
> clarification):
>
> - **Sibling method, not overload.** `compose_for_row()` stays for the
>   REST GET (matches the operator's Tools-tab picks). New
>   `compose_effective_tools_for_row()` handles registration-time
>   composition. Same-file placement immediately after `compose_for_row()`.
> - **Reuse F025 filter.** `acrossai_mcp_manager_server_tools` fires with
>   the widened pre-filter list. No new hook surface. Docblock + docs
>   updated. Companion-plugin authors get a strict superset of what they
>   saw before — no breaking change.
> - **REST GET unchanged.** `ToolsController::get_tools()` continues
>   returning `compose_for_row()` output (protocol + curated). The Tools
>   tab UI remains focused on the operator's explicit picks; the Abilities
>   tab remains the surface for per-server visibility overrides.
> - **Fail-open when Abilities API absent.** If `wp_get_abilities` is
>   unavailable, the F017 pass is skipped silently — server still
>   registers with protocol + curated. Matches F017 `AbilityExposureGate`
>   and F025 `filter_default_server_config` defensive patterns.
>
> **Public API surfaces preserved (grep-gate before + after — no surviving
> consumer permitted to change):**
>
> - `\AcrossAI_MCP_Manager\Includes\Database\MCPServer\ToolPolicy::compose_for_row( Row ): string[]`
> - `\AcrossAI_MCP_Manager\Includes\Database\MCPServer\ToolPolicy::split_payload( array ): array`
> - `\AcrossAI_MCP_Manager\Includes\Database\MCPServer\ToolPolicy::PROTOCOL_TOOLS`
> - `\AcrossAI_MCP_Manager\Includes\Database\MCPServer\ToolPolicy::COLUMN_MAP`
> - `\AcrossAI_MCP_Manager\Includes\Database\MCPServer\ToolPolicy::PROTOCOL_TOOL_METADATA`
> - `\AcrossAI_MCP_Manager\Includes\MCP\Controller::register_database_servers( \WP\MCP\Core\McpAdapter ): void`
> - `\AcrossAI_MCP_Manager\Includes\MCP\Controller::filter_default_server_config( mixed ): mixed`
> - `\AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\ExposureResolver::resolve( int, string, array ): bool`
> - `POST /acrossai-mcp-manager/v1/servers/{id}/tools` — F025 request/response shape unchanged.
> - `GET /acrossai-mcp-manager/v1/servers/{id}/tools` — F025 response shape unchanged; the `tools` array continues to reflect protocol + curated only.
> - `acrossai_mcp_manager_server_tools` filter — same signature (`string[] $tools, MCPServer\Row $server`), same call site, wider pre-filter composed set.
>
> **Public API surfaces added:**
>
> - `\AcrossAI_MCP_Manager\Includes\Database\MCPServer\ToolPolicy::compose_effective_tools_for_row( Row ): string[]` — new stateless helper.
>
> **Runtime contract for callers of the new method:**
>
> - Returns a deduped, `array_values()`-normalized, `strval`'d list of ability slugs.
> - Order stability (non-contractual but stable): protocol slugs first (COLUMN_MAP order), curated slugs next (insertion order from `MCPServerToolQuery::get_added_slugs`), F017-effective abilities last (in `wp_get_abilities()` iteration order).
> - When `wp_get_abilities()` is unavailable (`function_exists` false), the F017 pass is skipped — return value equals `compose_for_row()` output.
> - `ExposureResolver::resolve()` is called once per (server, ability) pair. The resolver's per-request static cache means the second and subsequent calls for the same pair are O(1).
> - Fires no additional actions or filters beyond what `compose_for_row()` already fires (none).
>
> ---
>
> **TASK-1 — Add `ToolPolicy::compose_effective_tools_for_row()`**
>
> File: `includes/Database/MCPServer/ToolPolicy.php`
>
> Add `use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\ExposureResolver;` alongside the existing `MCPServerTool\Query` import.
>
> Append the following method immediately after `compose_for_row()` and before `split_payload()`:
>
> ```php
> /**
>  * Compose the effective tool list for a server row, INCLUDING F017
>  * per-server ability exposure state.
>  *
>  * Superset of `compose_for_row()`:
>  *   1. Enabled protocol columns (F025) — three tool_* boolean columns.
>  *   2. Curated ability slugs (F020) — presence rows in wp_acrossai_mcp_server_tools.
>  *   3. F017-effective abilities — every ability where
>  *      ExposureResolver::resolve( $server_id, $slug, $meta ) === true.
>  *      "Effective" honors the row-in-table > mcp.public precedence per
>  *      DEC-ABILITY-OVERRIDE-RESOLUTION.
>  *
>  * Fail-open: if wp_get_abilities() is unavailable (function_exists
>  * false), the F017 pass is skipped — return equals compose_for_row().
>  *
>  * Used by Controller::register_database_servers() and
>  * Controller::filter_default_server_config() — the two server-registration
>  * paths. NOT used by ToolsController::get_tools() (the REST GET stays on
>  * compose_for_row() so the Tools tab UI reflects operator picks only).
>  *
>  * @since 0.1.0 (Feature 026)
>  * @param Row $row The server row.
>  * @return string[] The composed tool list including F017-effective abilities.
>  */
> public static function compose_effective_tools_for_row( Row $row ): array {
>     $tools = self::compose_for_row( $row );
>
>     if ( ! function_exists( 'wp_get_abilities' ) ) {
>         return $tools; // Fail-open — Abilities API not bootstrapped.
>     }
>
>     $server_id = (int) $row->id;
>     foreach ( \wp_get_abilities() as $ability ) {
>         $slug = (string) $ability->get_name();
>         if ( '' === $slug ) {
>             continue;
>         }
>         $meta = $ability->get_meta();
>         if ( ExposureResolver::resolve( $server_id, $slug, is_array( $meta ) ? $meta : array() ) ) {
>             $tools[] = $slug;
>         }
>     }
>
>     return array_values( array_unique( array_map( 'strval', $tools ) ) );
> }
> ```
>
> Do not otherwise touch the file. `compose_for_row()`, `split_payload()`, `PROTOCOL_TOOLS`, `COLUMN_MAP`, and `PROTOCOL_TOOL_METADATA` are all preserved verbatim.
>
> ---
>
> **TASK-2 — Swap Controller call sites to the new composer**
>
> File: `includes/MCP/Controller.php`
>
> Two targeted line replacements:
>
> - Line 142 (inside `register_database_servers()`, immediately before the `apply_filters( 'acrossai_mcp_manager_server_tools', ... )` call):
>   ```
>   -   $tools = ToolPolicy::compose_for_row( $server );
>   +   $tools = ToolPolicy::compose_effective_tools_for_row( $server );
>   ```
> - Line 247 (inside `filter_default_server_config()`, immediately before the `if ( empty( $tools ) )` short-circuit check):
>   ```
>   -   $tools = ToolPolicy::compose_for_row( $rows[0] );
>   +   $tools = ToolPolicy::compose_effective_tools_for_row( $rows[0] );
>   ```
>
> Update the filter's docblock at approximately line 155 (the block just above `apply_filters( 'acrossai_mcp_manager_server_tools', ... )`) to note the extended composition:
>
> ```
> - * Fired inside Controller::register_database_servers() per server,
> - * immediately before $adapter->create_server(). The initial list is
> - * the union of the row's enabled tool_* columns (protocol slugs) and
> - * the ability slugs saved in wp_acrossai_mcp_server_tools for this
> - * server_id. Callbacks may add or remove any slug freely.
> + * Fired inside Controller::register_database_servers() per server,
> + * immediately before $adapter->create_server(). The initial list is
> + * the union of THREE sources:
> + *   1. The row's enabled tool_* columns (F025 — protocol slugs).
> + *   2. Ability slugs saved in wp_acrossai_mcp_server_tools (F020 — curated).
> + *   3. F017-effective abilities — every ability where
> + *      ExposureResolver::resolve( $server_id, $slug, $meta ) === true.
> + *      Row-in-wp_acrossai_mcp_server_abilities beats meta.mcp.public per
> + *      DEC-ABILITY-OVERRIDE-RESOLUTION.
> + * Callbacks may add or remove any slug freely.
> ```
>
> Do not touch any other line. Do not touch `register_routes()`, `initialize_adapter()`, `has_any_enabled_server()`, `get_enabled_database_servers()`, or `get_adapter_status()`.
>
> ---
>
> **TASK-3 — `includes/REST/ToolsController.php` — NO CODE CHANGE (verification only)**
>
> Confirm via `grep -n "ToolPolicy::compose_" includes/REST/ToolsController.php` that:
>
> - `ToolsController::get_tools()` at line ~201 still calls `ToolPolicy::compose_for_row( $server_row )`.
> - No new call to `compose_effective_tools_for_row()` in this file.
>
> The GET semantic is preserved verbatim per F025's contract §"GET's `abilities` catalog…" in `specs/025-server-tools-registration-hooks/contracts/rest-tools-endpoint-semantics.md`.
>
> ---
>
> **TASK-4 — Update `docs/extending-server-tools.md`**
>
> File: `docs/extending-server-tools.md` (created by F025).
>
> Amend three sections:
>
> 1. **§Filter contract** — add a bullet to the "pre-filter composed list" description: *"3. F017-effective abilities — every ability where `ExposureResolver::resolve( $server_id, $slug, $meta )` returns true. Row-in-`wp_acrossai_mcp_server_abilities` beats `meta.mcp.public` per `DEC-ABILITY-OVERRIDE-RESOLUTION`."*
>
> 2. **§Arguments → `$tools`** — extend the description to note the wider composition and the ordering (protocol → curated → F017-effective).
>
> 3. **NEW §Interaction with the Abilities tab** (add between §"Composability" and §"Examples") — one paragraph explaining that toggling an ability off in the Abilities tab (writes `is_exposed = 0` row) removes it from `tools/list` on the next request, even if it has `mcp.public = true`. And vice versa: toggling ON an ability with `mcp.public = false` adds it to `tools/list`. This is by design and complements F017's call-time gate.
>
> ---
>
> **TASK-5 — PHPUnit coverage**
>
> File: `tests/phpunit/Database/MCPServer/ToolPolicyTest.php` (extend existing)
>
> Add 4 new test cases after the existing 9 F025 cases:
>
> ```
> public function test_compose_effective_includes_public_ability_with_no_override(): void
> public function test_compose_effective_excludes_public_ability_with_disabled_override(): void
> public function test_compose_effective_includes_non_public_ability_with_enabled_override(): void
> public function test_compose_effective_excludes_non_public_ability_with_no_override(): void
> ```
>
> Each case:
>
> - Seed a server row (existing setUp already does this).
> - Register a scratch ability via a helper that calls `wp_register_ability()` (skip the test if `function_exists( 'wp_register_ability' )` is false — Abilities API not bootstrapped in the harness).
> - Optionally seed a row in `wp_acrossai_mcp_server_abilities` via `MCPServerAbility\Query::instance()->upsert( $server_id, $slug, $is_exposed )`.
> - Call `ToolPolicy::compose_effective_tools_for_row( $row )`.
> - Assert the resulting array does / does not contain the ability slug.
>
> Add `ExposureResolver::_reset_cache_for_tests();` to the existing `setUp()` alongside the existing table truncations.
>
> File: `tests/phpunit/MCP/ControllerToolsInjectionTest.php` (extend existing)
>
> Add 1 new case at the end:
>
> ```
> public function test_register_database_servers_produces_f017_widened_composed_set(): void
> ```
>
> Seed a public ability + a server row; mock the adapter or call `apply_filters` directly (matching the existing F025 harness pattern); assert the pre-filter `$tools` array contains the public ability slug.
>
> ---
>
> **TASK-6 — Verify**
>
> - `composer phpcs` — zero errors on modified files.
> - `composer phpstan` — level 8, zero errors, no new baseline entries.
> - `composer test` — full PHPUnit suite green including the 5 new cases.
> - Grep audits:
>   ```
>   grep -n "compose_effective_tools_for_row" includes/
>   ```
>   Expected: exactly 3 matches — one in `ToolPolicy.php` (definition), two in `Controller.php` (call sites).
>   ```
>   grep -n "compose_for_row" includes/
>   ```
>   Expected: exactly 3 matches — one in `ToolPolicy.php` (definition), one in `ToolsController.php` (REST GET), one INTERNAL call inside `compose_effective_tools_for_row()`. Confirms the REST GET was NOT swapped.
>   ```
>   grep -n "apply_filters( 'acrossai_mcp_manager_server_tools'" includes/
>   ```
>   Expected: exactly 1 match — inside `register_database_servers()`. Confirms no new call site added.
> - Manual E2E — see the Manual Verification Checklist below.
>
> ---
>
> **CONSTRAINTS (violations = defect):**
>
> - MUST NOT modify F017's storage layer (`Schema.php`, `Query.php`, `Row.php` under `includes/Database/MCPServerAbility/`).
> - MUST NOT modify `ExposureResolver::resolve()` — it is the canonical DEC-ABILITY-OVERRIDE-RESOLUTION resolver. F026 CONSUMES it verbatim.
> - MUST NOT modify `AbilityExposureGate` — F017's call-time gate stays untouched; F026 only affects registration-time advertising.
> - MUST NOT modify `AbilitiesController` REST routes or `src/js/abilities.js`.
> - MUST NOT modify `ToolsController::get_tools()` — the REST GET stays on `compose_for_row()`.
> - MUST NOT modify `src/js/tools.js` — no Tools tab UI change.
> - MUST NOT modify the `wp_acrossai_mcp_server_tools` schema, `MCPServerToolQuery`, or `ToolExposureGate`.
> - MUST NOT touch any file under `vendor/wordpress/mcp-adapter/`.
> - MUST NOT add a new filter — reuse `acrossai_mcp_manager_server_tools`.
> - MUST NOT overload `compose_for_row()` — add a sibling method.
> - MUST NOT skip the fail-open branch — the `function_exists( 'wp_get_abilities' )` guard is required.
> - Post-implementation grep gates from TASK-6 all pass.

```markdown
# 3. Plan + guard + security
/speckit.memory-md.plan-with-memory
/speckit.architecture-guard.governed-plan
/speckit.security-review.plan

# 4. Tasks + guard
/speckit.tasks
/speckit.architecture-guard.governed-tasks

# 5. Implement + quality checks
/speckit.architecture-guard.governed-implement
composer dump-autoload
composer run phpcs
composer run phpstan

# 6. Review + memory + commit
/speckit.analyze
/speckit.architecture-guard.architecture-review
/speckit.security-review.staged
/speckit.memory-md.capture-from-diff
/speckit.git.commit
```

---

## Manual Verification Checklist

### TASK-1 — `ToolPolicy::compose_effective_tools_for_row()`

- [ ] Method exists on `ToolPolicy` and is `public static`.
- [ ] `use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\ExposureResolver;` present in the file header.
- [ ] Method body starts by calling `self::compose_for_row( $row )` (seed union).
- [ ] `function_exists( 'wp_get_abilities' )` guard present — fail-open when Abilities API absent.
- [ ] Iterates `\wp_get_abilities()` and calls `ExposureResolver::resolve( (int) $row->id, $slug, $meta )` per ability.
- [ ] Return statement: `array_values( array_unique( array_map( 'strval', $tools ) ) )`.

### TASK-2 — Controller call-site swaps

- [ ] `includes/MCP/Controller.php` line 142 now reads `$tools = ToolPolicy::compose_effective_tools_for_row( $server );`.
- [ ] `includes/MCP/Controller.php` line 247 now reads `$tools = ToolPolicy::compose_effective_tools_for_row( $rows[0] );`.
- [ ] Filter docblock at line ~155 lists all three composition sources (protocol / curated / F017-effective).

### TASK-3 — REST GET preserved

- [ ] `grep -n "compose_effective_tools_for_row" includes/REST/ToolsController.php` returns zero matches.
- [ ] `grep -n "compose_for_row" includes/REST/ToolsController.php` returns exactly one match (line ~201, inside `get_tools()`).

### TASK-4 — Docs

- [ ] `docs/extending-server-tools.md` §Filter contract lists three composition sources.
- [ ] `docs/extending-server-tools.md` has a new §"Interaction with the Abilities tab" section between Composability and Examples.

### TASK-5 — PHPUnit

- [ ] `tests/phpunit/Database/MCPServer/ToolPolicyTest.php` has 4 new `test_compose_effective_*` methods.
- [ ] `tests/phpunit/MCP/ControllerToolsInjectionTest.php` has 1 new `test_register_database_servers_produces_f017_widened_*` method.
- [ ] `ExposureResolver::_reset_cache_for_tests()` is called in every `setUp()` that touches per-server ability state.

### TASK-6 — Quality gates + grep audits

- [ ] `composer phpcs` — zero errors on the plugin.
- [ ] `composer phpstan` — zero errors, no new baseline.
- [ ] `composer test` — full suite green.
- [ ] `grep -n "compose_effective_tools_for_row" includes/` returns exactly 3 matches (1 definition + 2 call sites).
- [ ] `grep -n "compose_for_row" includes/` returns exactly 3 matches (1 definition + 1 REST GET + 1 internal seed call).
- [ ] `grep -n "apply_filters( 'acrossai_mcp_manager_server_tools'" includes/` returns exactly 1 match (unchanged from F025).

### End-to-end (default server)

- [ ] Enable the Default MCP Server.
- [ ] Ensure at least one WordPress ability is registered with `mcp.public = true` metadata (e.g., via a scratch mu-plugin calling `wp_register_ability()` with `'meta' => array( 'mcp' => array( 'public' => true ) )`).
- [ ] `curl -X POST <site>/wp-json/mcp/mcp-adapter-default-server -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'` — the new ability appears in the `tools` array alongside the three protocol tools.
- [ ] Open the Abilities tab for the default server; toggle the ability OFF (creates `is_exposed = 0` row).
- [ ] Re-issue `tools/list` — the ability is gone.
- [ ] Re-toggle ON — the ability reappears.

### End-to-end (database server)

- [ ] Create a database server, enable it.
- [ ] Same round-trip as above against `/wp-json/mcp/<slug>`.

### Filter sanity

- [ ] Temporarily register `add_filter( 'acrossai_mcp_manager_server_tools', fn( $t, $s ) => array_values( array_diff( $t, [ '<some-slug-that-was-added-by-F017-pass>' ] ) ), 10, 2 );` in a scratch mu-plugin — verify the slug disappears from `tools/list`. Confirms the F025 filter still fires post-F026-widening.

### F017 call-time gate regression

- [ ] With the ability now advertised, invoke `tools/call` for it — succeeds (F017's `AbilityExposureGate` at priority 20 allows it since the effective exposure is true).
- [ ] Toggle OFF via the Abilities tab; `tools/call` returns 403 `acrossai_mcp_ability_not_exposed` — unchanged from F017 baseline.

### F025 REST GET regression

- [ ] `curl GET /wp-json/acrossai-mcp-manager/v1/servers/{id}/tools` — response `tools` array still equals `compose_for_row()` output (protocol + curated only). The F017-effective abilities do NOT appear here. Confirms GET semantic is preserved.

### F025 POST regression

- [ ] `POST /wp-json/acrossai-mcp-manager/v1/servers/{id}/tools` with a `tools` array containing protocol + curated slugs still returns 200 with F025's split-write behavior unchanged.

---

## Pre-flight Attestation

**Captured**: 2026-07-14 during Plan-mode conversation with user, immediately after PR #28 (F025) merged.

**Attestation**: Feature 026 introduces no schema change, no destructive DDL, no new REST route, no new WordPress filter, and no vendor code modification. It adds exactly one new PHP method (`ToolPolicy::compose_effective_tools_for_row()`) and swaps two call sites from `compose_for_row()` → `compose_effective_tools_for_row()`. F017 and F025 storage layers, resolvers, gates, and REST contracts are preserved verbatim. Backwards compatibility: strict — the pre-F026 composed set is a strict subset of the F026 composed set, so no existing tool disappears from any server; new tools appear only for abilities the operator has already made visible via the Abilities tab or globally via `mcp.public = true`. The plugin is dev/local per Feature 011's pre-flight attestation; no additional attestation required.

**Attesting user**: raftaar1191@gmail.com

**Validity window**: 2026-07-14 → Feature 026 merge.
