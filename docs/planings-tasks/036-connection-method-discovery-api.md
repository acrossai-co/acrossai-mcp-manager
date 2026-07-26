# Planning: Public Connection-Method Discovery API (Feature 036)

Add a single public class `ConnectionMethodRegistry` under `public/Discovery/` that exposes every registered NPM method, MCP client, and AI connector as a unified, JSON-safe DTO list. This is the source-of-truth API a companion plugin (specifically a planned BuddyBoss add-on) queries to build its own per-server allowlist admin UI and to render frontend BuddyPress-profile connection surfaces for the methods an admin has permitted. This plugin owns "what methods exist and how are they described"; the consuming plugin owns "which are allowed to whom" and "where to render on the frontend."

Today no unified discovery surface exists. A third-party plugin has to call `ConnectorProfileRegistry::instance()->get_profiles()` for AI connectors, re-implement `MCPClientsBlock`'s hardcoded default array + the `acrossai_mcp_client_classes` filter loop + class-string validation for MCP clients, and read the `acrossai_mcp_npm_login_enabled` option manually for NPM (no filter at all — a hardcoded template). Three different call patterns; one requires duplicating internal logic from a Renderer class. F036 collapses all three into one contract with a stable DTO shape, honours every existing extension filter, and adds one new filter (`acrossai_mcp_npm_methods`) so NPM becomes extensible symmetrically with clients + AI connectors.

This is an **additive** feature — no existing behaviour changes. Existing consumers of `ConnectorProfileRegistry`, `AbstractMCPClient`, and the three tab renderers continue working unchanged. The new class is marked `@experimental` per `DEC-CLIENT-RENDERER-PUBLIC-API` (same policy as `public/Renderers/` sibling classes) until the plugin reaches 1.0.0, at which point the DTO shape is frozen. **Depends on F035** landing on `main` first: F036's `get_clients()` calls `AbstractMCPClient::get_all_registered_clients()` and reads per-client metadata via `$client->get_icon()` / `->get_description()` / etc. — both of which F035 introduces. Do NOT open the F036 branch off `main` until the F035 PR is merged.

---

## Speckit Workflow

```markdown
# 1. Branch
/speckit.git.feature "connection-method-discovery-api"

# 2. Specify
/speckit.specify "Add a public discovery class ConnectionMethodRegistry
under public/Discovery/ (namespace AcrossAI_MCP_Manager\\Public\\Discovery)
that exposes every registered NPM method, MCP client, and AI connector
as a unified list of plain-associative-array DTOs. Consumers: third-party
plugins (specifically a planned BuddyBoss add-on) that need to build
per-server allowlist admin UIs and render frontend connection surfaces.
Class is @experimental per DEC-CLIENT-RENDERER-PUBLIC-API. Singleton per
A2. Public methods: instance(), get_all() returning array with keys
'npm' / 'clients' / 'ai_connectors', get_npm_methods() returning array,
get_clients() returning array (delegates to F035
AbstractMCPClient::get_all_registered_clients() and reads metadata via
the F035 getter methods), get_ai_connectors() returning array (delegates
to ConnectorProfileRegistry::instance()->get_profiles()), find(string
category, string slug) returning ?array. Every DTO has keys: category
('npm' | 'client' | 'ai_connector'), slug (stable machine identifier),
name (translated display name), description (translated one-liner), icon
(emoji for clients / URL for ai_connectors / '' for npm), meta
(category-specific extras). Category-specific meta fields: npm has
command_template (e.g. 'npx -y @acrossai/mcp-manager --siteurl=%s
--server=%s') + enabled_option ('acrossai_mcp_npm_login_enabled'); client
has config_file + top_level_key + class (FQN); ai_connector has icon_url
(mirror of top-level icon) + has_redirect_whitelist (bool) + class (FQN).
Add TWO new filters: acrossai_mcp_npm_methods (fired inside
get_npm_methods() with the built-in NPM item as the seed — consumers
append/replace) and acrossai_mcp_connection_methods (fired ONCE on the
assembled get_all() result — cross-category concerns without duplicating
three filter registrations). Do NOT touch NpmClientBlock, MCPClientsBlock,
AIConnectorsTab, ConnectorProfileRegistry, AbstractConnectorProfile,
AbstractMCPClient (or any concrete client), or any admin partial. All
existing extension seams (acrossai_mcp_client_classes,
acrossai_mcp_manager_connector_profiles) MUST be honoured transparently
via delegation — never re-implemented here. Reuse existing utilities:
NpmClientBlock's option gate and command template (extract via a new
static NpmClientBlock::get_default_npm_method(): array helper if the
current template lives inline in render_body). Register a new PHPUnit
suite 'discovery' pointing at tests/phpunit/Public/Discovery/ and a
matching CI step in .github/workflows/phpunit.yml (follow the F030
test-infrastructure precedent for adding a new suite). Memory hygiene:
capture new DEC- entry recording the 'public discovery API delegates,
never re-implements' pattern; register companion INDEX.md row."
```

### Detailed Description for `/speckit.specify`

> **Before writing a single line of code, read and internalize all four of
> these governing documents in full:**
>
> 1. `AGENTS.md` — this plugin's singleton pattern (A2), hook registration
>    (A1 — Loader-only via `Main.php`; F036 does NOT wire any hooks),
>    text domain `'acrossai-mcp-manager'`, Before Commit Checklist.
> 2. This planning brief — `docs/planings-tasks/036-connection-method-discovery-api.md`.
> 3. **Pattern references** — three files that F036 delegates to (no
>    re-implementation):
>    - `includes/Connectors/ConnectorProfileRegistry.php` — the canonical
>      registry-shape F036 mirrors; `get_ai_connectors()` calls
>      `ConnectorProfileRegistry::instance()->get_profiles()` directly.
>    - `includes/MCPClients/AbstractMCPClient.php` (F035 post-refactor
>      state) — `get_clients()` calls
>      `AbstractMCPClient::get_all_registered_clients()` and reads
>      per-client metadata via the F035 getter methods
>      (`get_icon`, `get_description`, `get_config_file`,
>      `get_top_level_key`, `get_instructions`).
>    - `public/Renderers/NpmClientBlock.php` — source of the current NPM
>      command template + `acrossai_mcp_npm_login_enabled` option gate.
>      F036 either reads these values from a new static helper on that
>      class (`NpmClientBlock::get_default_npm_method(): array`) OR
>      re-declares the constants inside `ConnectionMethodRegistry` and
>      documents the drift risk. Prefer the helper.
> 4. **Constitutional decision governing this file's location + policy** —
>    `DEC-CLIENT-RENDERER-PUBLIC-API` in `docs/memory/DECISIONS.md`. F036
>    is a new consumer of the same `public/` namespace policy: mark the
>    class `@experimental May change without notice before 1.0.0`; DTO
>    shape freezes at 1.0.0.
>
> Every decision — method signature shape, DTO field names, filter names,
> singleton vs. static-service — must be justified against the above. If
> a choice is not explicitly covered, default to the sibling `ConnectorProfileRegistry`
> shape. Do not write code that would fail any Definition-of-Done gate:
> PHPStan level 8, PHPCS, all `__()` calls using the correct text domain.
>
> **Public API artifacts F036 introduces (grep-gate at end — MUST be the
> ONLY new symbols in the source tree):**
>
> - `\AcrossAI_MCP_Manager\Public\Discovery\ConnectionMethodRegistry::instance()`
> - `\AcrossAI_MCP_Manager\Public\Discovery\ConnectionMethodRegistry::get_all()`
> - `\AcrossAI_MCP_Manager\Public\Discovery\ConnectionMethodRegistry::get_npm_methods()`
> - `\AcrossAI_MCP_Manager\Public\Discovery\ConnectionMethodRegistry::get_clients()`
> - `\AcrossAI_MCP_Manager\Public\Discovery\ConnectionMethodRegistry::get_ai_connectors()`
> - `\AcrossAI_MCP_Manager\Public\Discovery\ConnectionMethodRegistry::find( string $category, string $slug ): ?array`
> - Filter `acrossai_mcp_npm_methods` (new; fired inside `get_npm_methods()`)
> - Filter `acrossai_mcp_connection_methods` (new; fired ONCE inside `get_all()` on the assembled 3-category array)
> - Static helper `\AcrossAI_MCP_Manager\Public\Renderers\NpmClientBlock::get_default_npm_method(): array` (new; extracted from `NpmClientBlock`)
>
> **Public API artifacts F036 depends on (must exist post-F035; grep-gate
> at start):**
>
> - `\AcrossAI_MCP_Manager\Includes\MCPClients\AbstractMCPClient::get_all_registered_clients()`
> - `\AcrossAI_MCP_Manager\Includes\MCPClients\AbstractMCPClient::get_icon()`
> - `\AcrossAI_MCP_Manager\Includes\MCPClients\AbstractMCPClient::get_description()`
> - `\AcrossAI_MCP_Manager\Includes\MCPClients\AbstractMCPClient::get_config_file()`
> - `\AcrossAI_MCP_Manager\Includes\MCPClients\AbstractMCPClient::get_top_level_key()`
> - `\AcrossAI_MCP_Manager\Includes\MCPClients\AbstractMCPClient::get_instructions()`
> - `\AcrossAI_MCP_Manager\Includes\Connectors\ConnectorProfileRegistry::instance()`
> - `\AcrossAI_MCP_Manager\Includes\Connectors\ConnectorProfileRegistry::get_profiles()`
>
> If any of the F035 symbols above are missing, halt: the F035 PR has not
> yet merged and F036 must wait.
>
> Pre-flight grep (records current usage patterns callers may already have
> that F036 either replaces or leaves untouched):
>
> ```bash
> grep -rEn 'ConnectorProfileRegistry::instance|get_all_registered_clients|acrossai_mcp_npm_login_enabled' \
>     --include='*.php' \
>     includes/ admin/ public/ tests/
> ```
>
> Hits inside plugin source are informational — they identify the current
> consumers of the same underlying data F036 will surface uniformly. No
> caller migration is in F036 scope; existing paths keep working.
>
> ---
>
> **TASK-1 — Extract NPM default method helper from `NpmClientBlock`**
>
> Files:
> - `public/Renderers/NpmClientBlock.php`
> - `tests/phpunit/Public/Renderers/NpmClientBlockTest.php` (add or extend)
>
> Read `public/Renderers/NpmClientBlock.php:88–130` (`render_body` +
> `render_command_ui`) before editing. Identify the current inline template
> string (`'npx -y @acrossai/mcp-manager --siteurl=%s --server=%s'`) and
> the option key (`'acrossai_mcp_npm_login_enabled'`).
>
> Add a new `public static function get_default_npm_method(): array` on
> `NpmClientBlock` that returns the pre-filter DTO for the built-in NPM
> item:
>
> ```php
> public static function get_default_npm_method(): array {
>     return array(
>         'category'    => 'npm',
>         'slug'        => 'npx-acrossai-mcp-manager',
>         'name'        => __( 'NPM (npx bridge)', 'acrossai-mcp-manager' ),
>         'description' => __( 'Copy-paste npx command for CLI-based MCP hosts.', 'acrossai-mcp-manager' ),
>         'icon'        => '',
>         'meta'        => array(
>             'command_template' => 'npx -y @acrossai/mcp-manager --siteurl=%s --server=%s',
>             'enabled_option'   => 'acrossai_mcp_npm_login_enabled',
>         ),
>     );
> }
> ```
>
> Rewire the existing `render_command_ui` (or wherever the template + option
> key are hardcoded) to READ from `self::get_default_npm_method()['meta']['command_template']`
> and `self::get_default_npm_method()['meta']['enabled_option']` — single
> source of truth for both the Renderer and F036's discovery API.
>
> Do NOT change the rendered HTML output on the NPM tab. Test the render
> output byte-identity if a snapshot test infrastructure exists; otherwise
> load the tab manually before + after and DOM-diff.
>
> Do NOT change any other method on `NpmClientBlock`. Do NOT change the
> `slug()` return value. Do NOT touch the singleton mechanics.
>
> ---
>
> **TASK-2 — Create `ConnectionMethodRegistry` class**
>
> Files:
> - `public/Discovery/ConnectionMethodRegistry.php` (NEW)
> - Namespace: `AcrossAI_MCP_Manager\Public\Discovery`
>
> Skeleton (singleton per A2, `@experimental` per DEC-CLIENT-RENDERER-PUBLIC-API):
>
> ```php
> namespace AcrossAI_MCP_Manager\Public\Discovery;
>
> use AcrossAI_MCP_Manager\Includes\Connectors\ConnectorProfileRegistry;
> use AcrossAI_MCP_Manager\Includes\MCPClients\AbstractMCPClient;
> use AcrossAI_MCP_Manager\Public\Renderers\NpmClientBlock;
>
> /**
>  * @experimental May change without notice before 1.0.0. See DEC-CLIENT-RENDERER-PUBLIC-API.
>  */
> final class ConnectionMethodRegistry {
>     private static ?self $instance = null;
>     private ?array $memoized_all = null;
>     public static function instance(): self { /* singleton */ }
>     private function __construct() {}
>
>     public function get_all(): array;
>     public function get_npm_methods(): array;
>     public function get_clients(): array;
>     public function get_ai_connectors(): array;
>     public function find( string $category, string $slug ): ?array;
> }
> ```
>
> `get_npm_methods()`:
> - Start with `[ NpmClientBlock::get_default_npm_method() ]`.
> - Fire `apply_filters( 'acrossai_mcp_npm_methods', $items )`.
> - Return the filter output (no additional validation beyond ensuring it's
>   an array — consumers are trusted at the filter boundary; malformed
>   entries will surface downstream via missing keys).
>
> `get_clients()`:
> - Call `AbstractMCPClient::get_all_registered_clients()` (F035 dependency).
> - Map each `AbstractMCPClient` instance to the unified DTO:
>   ```php
>   [
>       'category'    => 'client',
>       'slug'        => $client->get_client_slug(),
>       'name'        => $client->get_client_name(),
>       'description' => $client->get_description(),
>       'icon'        => $client->get_icon(),
>       'meta'        => [
>           'config_file'   => $client->get_config_file(),
>           'top_level_key' => $client->get_top_level_key(),
>           'class'         => get_class( $client ),
>       ],
>   ]
>   ```
> - Return the array (order preserved from `get_all_registered_clients()` — sorted by slug).
>
> `get_ai_connectors()`:
> - Call `ConnectorProfileRegistry::instance()->get_profiles()`.
> - Map each `AbstractConnectorProfile` instance to the unified DTO:
>   ```php
>   [
>       'category'    => 'ai_connector',
>       'slug'        => $profile->get_slug(),
>       'name'        => $profile->get_name(),
>       'description' => '',  // AbstractConnectorProfile has no description method today; keep empty for now
>       'icon'        => $profile->get_icon_url(),
>       'meta'        => [
>           'icon_url'                 => $profile->get_icon_url(),
>           'has_redirect_whitelist'   => count( $profile->get_redirect_uri_whitelist() ) > 0,
>           'class'                    => get_class( $profile ),
>       ],
>   ]
>   ```
> - Return the array (order preserved from `get_profiles()` — sorted by slug).
>
> `get_all()`:
> - Memoize per-request via `$this->memoized_all`.
> - Assemble: `[ 'npm' => $this->get_npm_methods(), 'clients' => $this->get_clients(), 'ai_connectors' => $this->get_ai_connectors() ]`.
> - Fire `apply_filters( 'acrossai_mcp_connection_methods', $all )`.
> - Return the filter output.
>
> `find( string $category, string $slug )`:
> - Look up the category array in `$this->get_all()`.
> - Linear scan for the matching slug (categories are small — 1 to ~50 items).
> - Return the matching DTO or `null`.
>
> Both new filters (`acrossai_mcp_npm_methods`, `acrossai_mcp_connection_methods`)
> MUST be documented with `@since 0.1.0` (or the version this ships in) +
> `@experimental May change without notice before 1.0.0`.
>
> ---
>
> **TASK-3 — Register `discovery` PHPUnit suite + CI step**
>
> Files:
> - `phpunit.xml.dist` (add new `<testsuite name="discovery">` entry)
> - `.github/workflows/phpunit.yml` (add new step running the discovery suite)
>
> Follow the F030 test-infrastructure precedent: add a suite entry pointing
> at `tests/phpunit/Public/Discovery/` (create the directory) with a matching
> CI workflow step using `tests/bootstrap-wp.php` (the class reaches into
> the abilities API + WP option storage transitively, so pure-service
> bootstrap is insufficient).
>
> Verify the suite runs (empty at first, but green) before adding tests in
> TASK-4.
>
> ---
>
> **TASK-4 — Write regression tests**
>
> Files:
> - `tests/phpunit/Public/Discovery/ConnectionMethodRegistryTest.php` (NEW)
>
> Cover:
> - `get_npm_methods()` returns the default item with the expected DTO
>   shape (category='npm', slug='npx-acrossai-mcp-manager', meta.command_template
>   non-empty, meta.enabled_option='acrossai_mcp_npm_login_enabled').
> - `get_npm_methods()` honours the new `acrossai_mcp_npm_methods` filter
>   (add a test fake NPM entry, assert it appears in the returned array).
> - `get_clients()` returns exactly 8 DTOs post-F035 with correct slugs
>   (Claude Desktop, Claude Code, VS Code, GitHub Copilot, Codex, Cursor,
>   Gemini, Custom — sorted alphabetically) AND each has non-empty
>   metadata fields (description, icon, config_file, top_level_key)
>   sourced from the F035 client-side getters.
> - `get_clients()` honours the existing `acrossai_mcp_client_classes`
>   filter transitively (test that a filter-added client shows up in the
>   discovery output with metadata sourced from its own getter methods).
> - `get_ai_connectors()` returns whatever `ConnectorProfileRegistry`
>   returns (test with a fake connector profile registered via
>   `acrossai_mcp_manager_connector_profiles`).
> - `get_ai_connectors()` returns `[]` when no companion plugins are
>   active (base plugin has zero profiles).
> - `get_all()` composes the three categories with keys `npm`, `clients`,
>   `ai_connectors`.
> - `get_all()` honours the new `acrossai_mcp_connection_methods` filter
>   (assert a filter that removes an entire category succeeds).
> - `find( category, slug )` returns the correct DTO for a valid pair,
>   `null` for a missing category or slug.
> - Unified DTO shape stability: every returned item across all three
>   categories has the same top-level keys (category, slug, name, description,
>   icon, meta). Use a helper assertion `assertDtoShape( $item )` to enforce.
>
> ---
>
> **TASK-5 — Memory hygiene**
>
> Files:
> - `docs/memory/DECISIONS.md`
> - `docs/memory/INDEX.md`
> - `README.txt`
>
> Capture a new decision entry:
>
> - **ID**: next available `D` slot (D36 if D35 is the F035 tail).
> - **Title**: `Public discovery APIs delegate, never re-implement — mirror the underlying registry contracts`.
> - **Body**: When a `public/` API surface needs to expose data already
>   owned by an internal registry (`ConnectorProfileRegistry`,
>   `AbstractMCPClient::get_all_registered_clients()`), it MUST delegate
>   to that registry — never re-hydrate from the underlying filter or
>   duplicate the validation logic. F036 established this by wrapping
>   both registries + adding one new registry (NPM) with the same
>   filter-with-validation shape. Rejected alternative: inline the
>   filter loops inside `ConnectionMethodRegistry` for "self-containment"
>   — creates two enumeration paths per subsystem that will inevitably
>   drift (exactly the class of bug F035 fixed for MCP clients).
>   Consequence: `public/Discovery/` MUST NOT `apply_filters` on any
>   filter already owned by an `includes/` registry — delegate the whole
>   call to that registry's public method.
>
> Companion `INDEX.md` row per FR-025.
>
> Changelog entry (README.txt) under `= Unreleased =` (create if missing;
> if F035 already opened one, append to it):
>
> ```markdown
> * **Feature 036 — Public connection-method discovery API.** New singleton `\AcrossAI_MCP_Manager\Public\Discovery\ConnectionMethodRegistry` under `public/Discovery/` exposes every registered NPM method, MCP client, and AI connector as a unified list of plain-associative-array DTOs — the single canonical entry point for third-party plugins (planned BuddyBoss add-on) to enumerate what connection methods this plugin makes available on a given site. Public methods: `instance()`, `get_all()` (returns 3-category array), `get_npm_methods()`, `get_clients()`, `get_ai_connectors()`, `find(category, slug)`. Every DTO has stable top-level keys (`category`, `slug`, `name`, `description`, `icon`, `meta`) safe for JSON serialization + third-party DB storage. Delegates to existing registries: `AbstractMCPClient::get_all_registered_clients()` (F035) for clients, `ConnectorProfileRegistry::instance()->get_profiles()` for AI connectors. Two new extension filters: `acrossai_mcp_npm_methods` (finally makes NPM extensible symmetrically with clients + connectors) and `acrossai_mcp_connection_methods` (fires ONCE on the assembled `get_all()` result — cross-category concerns without triple filter registration). `@experimental` per `DEC-CLIENT-RENDERER-PUBLIC-API` — DTO shape freezes at 1.0.0. Zero existing behaviour changes; entirely additive. No admin UI, no database schema, no new hooks beyond the two documented filters.
> ```
>
> ---
>
> **Definition of Done gates:**
>
> - PHPStan level 8 + PHPCS individually green on every touched file per task.
> - `vendor/bin/phpunit --testsuite=discovery` green.
> - No regressions in existing suites (`mcpclients`, `abilities`, `oauth`, etc.).
> - Grep gate: no `apply_filters( 'acrossai_mcp_client_classes'` inside
>   `public/Discovery/` (that filter is owned by `AbstractMCPClient::get_all_registered_clients()`
>   — F036 delegates, never re-fires).
> - Grep gate: no `apply_filters( 'acrossai_mcp_manager_connector_profiles'`
>   inside `public/Discovery/` (same reason — owned by `ConnectorProfileRegistry`).
> - Every `__()` call uses `'acrossai-mcp-manager'` text domain.

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
composer test

# 6. Review + memory + commit
/speckit.analyze
/speckit.architecture-guard.architecture-review
/speckit.security-review.staged
/speckit.memory-md.capture-from-diff
/speckit.git.commit
```

---

## Manual Verification Checklist

### TASK-1 — NPM default method helper
- [ ] `NpmClientBlock::get_default_npm_method(): array` exists as a public static.
- [ ] Returned DTO has all six top-level keys (`category`, `slug`, `name`, `description`, `icon`, `meta`) with expected values.
- [ ] `meta.command_template` equals the pre-refactor inline template string byte-for-byte.
- [ ] `meta.enabled_option` equals `'acrossai_mcp_npm_login_enabled'`.
- [ ] `NpmClientBlock::render_body()` output on the NPM tab is byte-identical to pre-refactor (DOM-diff or snapshot).
- [ ] No changes to the singleton mechanics, the `slug()` return, or any other public method.

### TASK-2 — `ConnectionMethodRegistry` class
- [ ] `public/Discovery/ConnectionMethodRegistry.php` exists with namespace `AcrossAI_MCP_Manager\Public\Discovery`.
- [ ] Class is `final`, singleton via `instance()` per A2, private constructor, class docblock includes `@experimental May change without notice before 1.0.0`.
- [ ] `get_npm_methods()` returns an array containing the default NPM item (from `NpmClientBlock::get_default_npm_method()`) and fires `acrossai_mcp_npm_methods` filter.
- [ ] `get_clients()` delegates to `AbstractMCPClient::get_all_registered_clients()` and does NOT re-fire `acrossai_mcp_client_classes` itself.
- [ ] `get_ai_connectors()` delegates to `ConnectorProfileRegistry::instance()->get_profiles()` and does NOT re-fire `acrossai_mcp_manager_connector_profiles` itself.
- [ ] `get_all()` returns 3-key array (`npm`, `clients`, `ai_connectors`), memoized per-request, fires `acrossai_mcp_connection_methods` ONCE on the assembled result.
- [ ] `find(category, slug)` returns the matching DTO or `null`.
- [ ] Every DTO across all three categories has the same top-level keys (`category`, `slug`, `name`, `description`, `icon`, `meta`).

### TASK-3 — `discovery` PHPUnit suite + CI
- [ ] `phpunit.xml.dist` has a `<testsuite name="discovery">` entry pointing at `tests/phpunit/Public/Discovery/`.
- [ ] `.github/workflows/phpunit.yml` has a matching step running the discovery suite via `tests/bootstrap-wp.php`.
- [ ] The suite runs (green) on CI even before TASK-4 tests are added (empty suite is fine).

### TASK-4 — Regression tests
- [ ] `tests/phpunit/Public/Discovery/ConnectionMethodRegistryTest.php` exists and covers every scenario listed in the Detailed Description TASK-4.
- [ ] All tests pass locally under `vendor/bin/phpunit --testsuite=discovery`.
- [ ] Existing suites (`mcpclients`, `abilities`, `oauth`, `cli-rest`, `admin`, `renderers`, `database`, `mcp`) remain green.

### TASK-5 — Memory hygiene
- [ ] New decision entry captured in `docs/memory/DECISIONS.md` (delegation-not-re-implementation pattern).
- [ ] Companion `INDEX.md` row registered.
- [ ] `README.txt` `= Unreleased =` changelog contains the F036 bullet.

### Discovery-API end-to-end smoke test
- [ ] From an mu-plugin or scratch test script, call `\AcrossAI_MCP_Manager\Public\Discovery\ConnectionMethodRegistry::instance()->get_all()`.
- [ ] Assert `count( $result['npm'] ) >= 1` (base plugin default) and item[0] has the expected slug.
- [ ] Assert `count( $result['clients'] ) === 8` (all F035 built-in clients) and items are sorted alphabetically by slug.
- [ ] Assert `count( $result['ai_connectors'] ) === 0` on a clean install (no companion plugins active).
- [ ] Register a fake AI connector profile via `acrossai_mcp_manager_connector_profiles`; assert `get_ai_connectors()` returns 1 item with the expected slug + icon URL.
- [ ] Register a fake NPM method via `acrossai_mcp_npm_methods`; assert `get_npm_methods()` returns 2 items.
- [ ] Register a filter on `acrossai_mcp_connection_methods` that removes the entire `clients` category; assert `get_all()['clients']` is empty for that request.

### Grep gates (blocker before merge)
- [ ] `grep -rn "apply_filters.*acrossai_mcp_client_classes" public/Discovery/` returns zero hits (delegate, don't re-fire).
- [ ] `grep -rn "apply_filters.*acrossai_mcp_manager_connector_profiles" public/Discovery/` returns zero hits (delegate, don't re-fire).
- [ ] `grep -rn "ConnectionMethodRegistry" includes/` returns zero hits (public/ layer must never be imported into includes/ — one-way dependency).

### Quality gates (all must be green before commit)
- [ ] PHPStan level 8 — zero errors on all touched files.
- [ ] PHPCS — zero errors on all touched files.
- [ ] `composer test` — full PHPUnit suite green.
- [ ] `composer dump-autoload` — succeeds with zero warnings.
- [ ] CI green on all 8 required checks + the new discovery-suite step.

---

## Related work (context, not scope)

- **F035** (`docs/planings-tasks/035-mcp-client-metadata-refactor.md`) — **hard prerequisite**. F036 calls `AbstractMCPClient::get_all_registered_clients()` and reads per-client metadata via the five getter methods F035 introduces. Do not open the F036 branch until the F035 PR is merged to `main`.
- **F033** (`docs/planings-tasks/033-f030-permission-callback-wrapper-fix.md`) — shipped in 0.1.7. Unrelated subsystem; referenced only as the memory-supersession pattern precedent.
- **F034** (proposed — GitHub [issue #46](https://github.com/acrossai-co/acrossai-mcp-manager/issues/46)) — filter-time eligibility gate for `PermissionOverrideProcessor`. Independent branch, independent subsystem.
- **BuddyBoss add-on plugin** (external — separate repository) — the motivating consumer for F036. Once F036 ships, the add-on can call `ConnectionMethodRegistry::instance()->get_all()` to enumerate connection methods, persist per-server allowlists in its own DB, and render allowed methods on the frontend BuddyPress user profile. This add-on is NOT part of the base plugin and NOT in F036 scope.
