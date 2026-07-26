# Planning: MCP Client Metadata + Filter-Aware Enumeration Refactor (Feature 035)

Align the MCP client subsystem (`includes/MCPClients/` + `public/Renderers/MCPClientsBlock.php`) with the pattern already in use for AI connector profiles (`includes/Connectors/AbstractConnectorProfile.php` + `includes/Connectors/ConnectorProfileRegistry.php`). Every concrete MCP client class becomes fully self-describing (icon, description, config file, top-level key, instructions all declared as method overrides on the class itself), enumeration collapses to a single canonical filter-aware entry point, and the display Renderer stops carrying display metadata in a private constant.

This refactor is motivated by three competing enumeration patterns that currently disagree with each other and by the fact that third-party MCP client classes contributed via the `acrossai_mcp_client_classes` filter have no seam for declaring their own display metadata. `AbstractMCPClient::get_all_clients()` (glob-based, filter-blind) returns one list. `MCPClientsBlock::render_body()` (hardcoded default array + filter loop) returns a different list — and only this second path is filter-aware. `MCPClientsBlock::CLIENT_META` (private const keyed by slug) is the sole source of display metadata for the 8 built-in clients and contains zero entries for any third-party client. F036 (public connection-method discovery API for a planned BuddyBoss add-on) requires a single canonical enumeration that returns fully-populated DTOs regardless of contribution source; F035 removes the architectural drift that would otherwise leak into F036's public contract forever.

The refactor is **backwards-compatible with third-party subclasses**: five new metadata methods (`get_icon`, `get_description`, `get_config_file`, `get_top_level_key`, `get_instructions`) are added to `AbstractMCPClient` with empty-string defaults, so any existing external subclass compiles and runs unchanged. The `acrossai_mcp_client_classes` filter contract is preserved verbatim (still accepts an array of FQN strings; validation shape unchanged — `is_string` + `class_exists` + `is_subclass_of`, plus new slug regex + dedup already used by the connector pattern). Rendered output for the 8 built-in clients on the server-edit → Clients tab MUST be byte-identical pre- and post-refactor.

---

## Speckit Workflow

```markdown
# 1. Branch
/speckit.git.feature "mcp-client-metadata-refactor"

# 2. Specify
/speckit.specify "Refactor the MCP client subsystem to align with the
AbstractConnectorProfile / ConnectorProfileRegistry shape already in use for
AI connector profiles. Add five metadata methods to AbstractMCPClient
(get_icon, get_description, get_config_file, get_top_level_key,
get_instructions) with empty-string defaults so any existing external
subclass keeps working. Migrate the values in MCPClientsBlock::CLIENT_META
verbatim into overrides on the eight concrete client classes (ClaudeDesktop,
ClaudeCode, VSCode, GitHubCopilot, Codex, Cursor, Gemini, Custom). Add
AbstractMCPClient::get_all_registered_clients() as the sole canonical
enumeration path: hardcoded DEFAULT_CLIENT_CLASSES constant (moved from
MCPClientsBlock) + acrossai_mcp_client_classes filter + instanceof + FQN
class_exists validation + slug regex validation ([a-z0-9-]{1,64}) + dedup
by slug with _doing_it_wrong under WP_DEBUG + ksort by slug ascending —
mirroring ConnectorProfileRegistry::get_profiles() line-for-line. Delete
AbstractMCPClient::get_all_clients() (the glob-based enumeration that
ignores the filter). Delete MCPClientsBlock::CLIENT_META. Delete
MCPClientsBlock's inline default-classes array + filter-loop; call
AbstractMCPClient::get_all_registered_clients() and read metadata from
each client instance via the new getter methods. Preserve the public
acrossai_mcp_client_classes filter contract exactly (still an array of FQN
strings, still applied with the same defaults). Do not touch any concrete
client's get_client_slug / get_client_name / get_config_snippet method
bodies. Do not touch AbstractConnectorProfile, ConnectorProfileRegistry,
NpmClientBlock, AccessControlBlock, or any file outside includes/MCPClients/
and public/Renderers/MCPClientsBlock.php. Render output for all eight
built-in clients on the server-edit Clients tab MUST be byte-identical
before and after the refactor. Regression tests: get_all_registered_clients
enumeration + validation + filter behaviour, per-client metadata getter
non-empty assertions, MCPClientsBlock render snapshot for at least one
representative client. Memory hygiene per PATTERN-MEMORY-SUPERSESSION-VS-
ANNOTATION: any DEC-CLIENT-META-* entries (if they exist) marked Superseded
(Feature 035); capture a new DEC- entry recording the 'self-contained
subsystem contract' pattern (subsystem's abstract class owns all metadata
and enumeration; Renderers are consumers)."
```

### Detailed Description for `/speckit.specify`

> **Before writing a single line of code, read and internalize all four of
> these governing documents in full:**
>
> 1. `AGENTS.md` — this plugin's singleton pattern, hook registration rules
>    (A1 — Loader-only via `Main.php`), pure-service exemption (A11 —
>    `AbstractMCPClient` is stateless per FR-008/FR-009 and this refactor
>    preserves that), Before Commit Checklist.
> 2. This planning brief — `docs/planings-tasks/035-mcp-client-metadata-refactor.md`.
> 3. **Pattern reference** — `includes/Connectors/AbstractConnectorProfile.php`
>    + `includes/Connectors/ConnectorProfileRegistry.php`. These two files
>    are the canonical shape F035 aligns to. Read them in full before
>    editing anything. Key symmetries to reproduce for the client subsystem:
>    - **Contract-on-the-class**: every displayable field is a method on the
>      subclass (`get_slug`, `get_name`, `get_icon_url`, ...), not a private
>      const in a display class.
>    - **One filter, one registry**: `apply_filters(...)` fires exactly once
>      inside a memoized static registry method that returns the sorted +
>      validated list.
>    - **Validation shape**: instanceof / FQN check + slug regex
>      `/\A[a-z0-9-]{1,64}\z/` + dedup by slug with `_doing_it_wrong` under
>      `WP_DEBUG` + `ksort` by slug ascending.
> 4. **Current state** — `includes/MCPClients/AbstractMCPClient.php`
>    (particularly the glob-based `get_all_clients()` at lines 105–128,
>    which this refactor deletes) + `public/Renderers/MCPClientsBlock.php`
>    (particularly `CLIENT_META` at lines 55–112 and the inline
>    default-classes array + filter loop at lines 167–198, both of which
>    this refactor deletes).
>
> Every decision — method signature shape, defaults, deletion order — must
> be justified against the above. If a choice is not explicitly covered,
> default to the sibling subsystem's shape (`AbstractConnectorProfile` /
> `ConnectorProfileRegistry`). Do not write code that would fail any
> Definition-of-Done gate: PHPStan level 8, PHPCS, all `__()` calls using
> the correct text domain `'acrossai-mcp-manager'`.
>
> **Public API artifacts to preserve verbatim (grep-gate before + after):**
>
> - `\AcrossAI_MCP_Manager\Includes\MCPClients\AbstractMCPClient::get_client_slug`
> - `\AcrossAI_MCP_Manager\Includes\MCPClients\AbstractMCPClient::get_client_name`
> - `\AcrossAI_MCP_Manager\Includes\MCPClients\AbstractMCPClient::get_config_snippet`
> - `\AcrossAI_MCP_Manager\Includes\MCPClients\AbstractMCPClient::EMPTY_TOKEN_PLACEHOLDER`
> - `\AcrossAI_MCP_Manager\Includes\MCPClients\AbstractMCPClient::SERVER_KEY_FALLBACK`
> - `\AcrossAI_MCP_Manager\Public\Renderers\MCPClientsBlock::instance()`
> - `\AcrossAI_MCP_Manager\Public\Renderers\MCPClientsBlock::slug()` (returns `'clients'`)
> - The `acrossai_mcp_client_classes` filter — contract (array of FQN
>   strings), validation semantic (invalid FQNs silently skipped per
>   SEC-013-008), and the default list of eight class FQNs supplied to it.
>
> **Symbols intentionally removed by this refactor (grep-gate MUST return
> zero matches post-implementation):**
>
> - `\AcrossAI_MCP_Manager\Includes\MCPClients\AbstractMCPClient::get_all_clients` (glob-based path).
> - `\AcrossAI_MCP_Manager\Public\Renderers\MCPClientsBlock::CLIENT_META` (private const).
>
> Pre-flight grep (records every current caller whose behavior must be
> unchanged after the refactor — every hit either resolves to a still-existing
> symbol OR is migrated in-scope):
>
> ```bash
> grep -rEn 'CLIENT_META|get_all_clients\(\)|acrossai_mcp_client_classes' \
>     --include='*.php' \
>     includes/ admin/ public/ tests/
> ```
>
> Every `CLIENT_META` hit MUST be migrated in TASK-4 (call the new getter
> on the client instance instead). Every `get_all_clients()` hit MUST be
> migrated in TASK-3 (replace with `get_all_registered_clients()`). Every
> `acrossai_mcp_client_classes` hit outside `MCPClientsBlock.php` +
> `AbstractMCPClient.php` MUST still work identically after TASK-3.
>
> ---
>
> **TASK-1 — Extend `AbstractMCPClient` with five metadata methods**
>
> Files:
> - `includes/MCPClients/AbstractMCPClient.php` (append five methods)
> - `tests/phpunit/MCPClients/AbstractMCPClientTest.php` (add default-return assertions)
>
> Add these five methods to `AbstractMCPClient` after the existing
> `get_config_snippet()` abstract declaration:
>
> ```php
> /** Icon hint — emoji or short marker. Empty when unset. */
> public function get_icon(): string { return ''; }
>
> /** One-line description (translated). Empty when unset. */
> public function get_description(): string { return ''; }
>
> /** Config file path hint (e.g. '~/.claude.json'). Empty when unset. */
> public function get_config_file(): string { return ''; }
>
> /** JSON/TOML top-level key the snippet gets pasted under. Empty when unset. */
> public function get_top_level_key(): string { return ''; }
>
> /** Setup instructions (translated). Empty when unset. */
> public function get_instructions(): string { return ''; }
> ```
>
> These are non-abstract with empty-string defaults so any existing
> third-party subclass compiles and runs unchanged (additive, backwards-
> compatible per DEC-CONNECTOR-PROFILE-CONTRACT-ADDITIVE-DEFAULTS if that
> decision exists; otherwise establish the pattern here).
>
> Test additions: assert that a bare test-only subclass extending
> `AbstractMCPClient` with ONLY the three original abstract methods
> implemented returns `''` from each of the five new methods.
>
> Do NOT touch the abstract's existing helper methods (`build_server_url`,
> `derive_server_key`, `safe_token`, `current_username`, `redact_token`).
> Do NOT change `EMPTY_TOKEN_PLACEHOLDER` or `SERVER_KEY_FALLBACK`.
>
> ---
>
> **TASK-2 — Migrate `CLIENT_META` values into concrete client overrides**
>
> Files:
> - `includes/MCPClients/ClaudeDesktopClient.php`
> - `includes/MCPClients/ClaudeCodeClient.php`
> - `includes/MCPClients/VSCodeClient.php`
> - `includes/MCPClients/GitHubCopilotClient.php`
> - `includes/MCPClients/CodexClient.php`
> - `includes/MCPClients/CursorClient.php`
> - `includes/MCPClients/GeminiClient.php`
> - `includes/MCPClients/CustomClient.php`
>
> Read `public/Renderers/MCPClientsBlock.php:55–112` (the `CLIENT_META`
> const) before editing. Each entry has five fields — `emoji`, `description`,
> `config_file`, `top_level_key`, `instructions`. For each of the eight
> concrete client classes, add five method overrides that return the current
> `CLIENT_META[$slug]` values verbatim:
>
> ```php
> // Example for ClaudeDesktopClient:
> public function get_icon(): string { return '🍰'; }
> public function get_description(): string { return __( 'Anthropic Claude Desktop App', 'acrossai-mcp-manager' ); }
> public function get_config_file(): string { return '~/Library/Application Support/Claude/claude_desktop_config.json'; }
> public function get_top_level_key(): string { return 'mcpServers'; }
> public function get_instructions(): string {
>     return __( 'Generate a password → copy the JSON → open the config file path above → paste under the top-level key → restart Claude Desktop.', 'acrossai-mcp-manager' );
> }
> ```
>
> - `description` + `instructions` MUST be wrapped in `__()` with the
>   `acrossai-mcp-manager` text domain. Config file paths + top-level keys
>   + emoji MUST stay untranslated (they are technical strings, not UI copy).
> - Verbatim string moves — do NOT reword, do NOT restructure.
> - Update or add per-client unit tests to assert each of the five getters
>   returns exactly the migrated value (test file naming: existing
>   `AbstractMCPClientTest.php` covers helpers; per-client tests may not yet
>   exist — create `tests/phpunit/MCPClients/<Client>Test.php` where missing,
>   or add a data-provider-parameterized test in a new
>   `ConcreteClientMetadataTest.php`).
>
> Do NOT touch existing `get_client_slug`, `get_client_name`, or
> `get_config_snippet` methods on any client.
>
> ---
>
> **TASK-3 — Add canonical enumeration to `AbstractMCPClient`; delete glob-based path**
>
> Files:
> - `includes/MCPClients/AbstractMCPClient.php` (add `DEFAULT_CLIENT_CLASSES` + `get_all_registered_clients()`; delete `get_all_clients()`)
> - `tests/phpunit/MCPClients/GetAllRegisteredClientsTest.php` (NEW)
>
> Move the default client-class list from `MCPClientsBlock` to
> `AbstractMCPClient` as a `public const DEFAULT_CLIENT_CLASSES = array(
> ClaudeDesktopClient::class, ..., CustomClient::class );` — the eight
> current entries in the same order. This lives on the abstract because
> the client subsystem owns its own default set; the display layer is a
> consumer, not the source of truth.
>
> Add `public static function get_all_registered_clients(): array` mirroring
> `ConnectorProfileRegistry::get_profiles()` at
> `includes/Connectors/ConnectorProfileRegistry.php:57–118` line-for-line
> (adapted for the FQN-string contribution shape used by the client filter,
> vs. the instance-contribution shape used by the connector filter):
>
> 1. Apply `acrossai_mcp_client_classes` filter with `DEFAULT_CLIENT_CLASSES`.
> 2. For each FQN in the returned array: `is_string` guard, `class_exists`
>    guard, `is_subclass_of( self::class )` guard. Skip invalid entries
>    silently per SEC-013-008 (no `_doing_it_wrong` on FQN-shape validation
>    — that matches the current `MCPClientsBlock::render_body` behaviour).
> 3. Instantiate the class.
> 4. Read `$instance->get_client_slug()`. Validate against
>    `/\A[a-z0-9-]{1,64}\z/`. On empty/invalid slug: `_doing_it_wrong`
>    under `WP_DEBUG` and skip.
> 5. Dedup by slug with `_doing_it_wrong` on duplicates under `WP_DEBUG`
>    (later-wins, matching connector pattern).
> 6. `ksort` by slug ascending; `array_values` normalize.
>
> Delete `AbstractMCPClient::get_all_clients()` (lines 105–128 pre-refactor).
> Every external caller (grep from the pre-flight above) MUST have been
> migrated to `get_all_registered_clients()` in this task or an earlier
> one; deletion at the end of this task must NOT break any consumer.
>
> New test file `GetAllRegisteredClientsTest.php` covering:
> - Default state: returns exactly the eight built-in slugs in alphabetical
>   order (assert full list matches expected order).
> - Third-party contribution via `acrossai_mcp_client_classes` filter with
>   a valid subclass FQN: appears in the returned list, sort order preserved.
> - Invalid contributions silently skipped: non-string entry, missing class,
>   class not extending `AbstractMCPClient`.
> - Bad slug rejected with `_doing_it_wrong`: subclass returning empty slug,
>   subclass returning slug with uppercase / underscore / >64 chars.
> - Duplicate slugs: `_doing_it_wrong` under `WP_DEBUG` + later-wins in
>   the returned list.
>
> ---
>
> **TASK-4 — Rewire `MCPClientsBlock` to consume the canonical source**
>
> Files:
> - `public/Renderers/MCPClientsBlock.php`
> - `tests/phpunit/Public/Renderers/MCPClientsBlockRenderTest.php` (NEW — render snapshot)
>
> In `MCPClientsBlock::render_body()`, replace the inline default-classes
> array + filter loop (current lines 167–198) with a single call:
>
> ```php
> $clients = AbstractMCPClient::get_all_registered_clients();
> ```
>
> Everywhere the current code reads `self::CLIENT_META[$slug]`, replace
> with the corresponding method call on the client instance:
>
> - `CLIENT_META[$slug]['emoji']` → `$client->get_icon()`
> - `CLIENT_META[$slug]['description']` → `$client->get_description()`
> - `CLIENT_META[$slug]['config_file']` → `$client->get_config_file()`
> - `CLIENT_META[$slug]['top_level_key']` → `$client->get_top_level_key()`
> - `CLIENT_META[$slug]['instructions']` → `$client->get_instructions()`
>
> Delete the entire `CLIENT_META` const declaration (lines 55–112 pre-refactor).
>
> The empty-client-list guard (`if ( empty( $clients ) )` at line 200) and
> the sub-client-slug selection logic (lines 208–218) are preserved verbatim
> — they operate on the client instance list regardless of source.
>
> Render byte-identity regression test: snapshot the rendered HTML for at
> least one representative client (`claude-desktop`) pre- and post-refactor
> and assert equality. If snapshot testing infrastructure doesn't exist,
> write a hand-authored assertion of key DOM markers (emoji character
> present in sub-nav, config-file path rendered, instructions text rendered).
>
> Do NOT touch `slug()` (returns `'clients'`), `instance()`, the
> constructor, or any of the private render helpers below `render_body()`
> beyond the CLIENT_META lookups.
>
> ---
>
> **TASK-5 — Grep audit + Activator/Main verification**
>
> Run the pre-flight grep from the Governing Docs list again. Verify:
>
> - Every hit for `CLIENT_META` is inside `MCPClientsBlock.php`
>   (should be zero after TASK-4 deletes the const; if any survive, they're
>   stale references that need migration).
> - Every hit for `get_all_clients()` is zero (should be after TASK-3).
> - Every hit for `acrossai_mcp_client_classes` is INSIDE
>   `AbstractMCPClient::get_all_registered_clients()` or inside an mu-plugin
>   / test fixture registering a contribution — the filter is the sole
>   remaining extension seam.
>
> No files outside `includes/MCPClients/` and
> `public/Renderers/MCPClientsBlock.php` should be modified in this feature.
> If the grep surfaces a caller elsewhere (`admin/Partials/`,
> `public/Renderers/AccessControlBlock.php`, `includes/OAuth/`), that caller
> lives in a different subsystem and either (a) already resolves against
> preserved public API (fine), or (b) needs a caller-side follow-up that is
> OUT of Feature 035 scope (open an issue).
>
> ---
>
> **TASK-6 — Memory hygiene**
>
> Files:
> - `docs/memory/DECISIONS.md` (append; optionally supersede existing entries)
> - `docs/memory/INDEX.md` (register the new decision entry + any supersession)
> - `README.txt` (append changelog entry to a new `= Unreleased =` section if none exists, else the existing one)
>
> Search `docs/memory/DECISIONS.md` + `INDEX.md` for entries mentioning
> `CLIENT_META`, `MCPClientsBlock::CLIENT_META`, or
> `AbstractMCPClient::get_all_clients` — if any exist, mark them Superseded
> (Feature 035) per PATTERN-MEMORY-SUPERSESSION-VS-ANNOTATION, keeping the
> original body intact.
>
> Capture a new decision entry recording the "self-contained subsystem
> contract" pattern this refactor establishes:
>
> - **ID**: next available `D` slot per `docs/memory/INDEX.md` count (D35
>   if D34 is the current tail).
> - **Title**: `Self-contained subsystem contract — abstract base owns metadata + enumeration; Renderers are consumers only`.
> - **Body**: any subsystem with (a) an abstract base + concrete subclasses
>   contributed via a filter AND (b) display metadata per subclass MUST
>   declare the metadata on the abstract as method-with-default (never in
>   a private const on the Renderer) AND MUST expose enumeration via a
>   single static method on the abstract that fires the filter with a
>   canonical default list. Reference pattern: `ConnectorProfileRegistry`
>   (F021). Applied to MCP clients in F035. Rejected alternative: keep
>   display metadata in the Renderer for "separation of concerns" — this
>   creates a metadata-orphan problem for third-party contributions that
>   Renderers can't know about.
>
> Companion INDEX.md row per FR-025.
>
> Changelog entry (README.txt): under `= Unreleased =` (create if missing),
> append a bullet mirroring the F031 / F030 shape:
>
> ```markdown
> * **Feature 035 — MCP client subsystem refactor: metadata methods + canonical filter-aware enumeration.** Each concrete MCP client class (`ClaudeDesktopClient`, `ClaudeCodeClient`, `VSCodeClient`, `GitHubCopilotClient`, `CodexClient`, `CursorClient`, `GeminiClient`, `CustomClient`) now declares its own display metadata via five new methods on `AbstractMCPClient` (`get_icon`, `get_description`, `get_config_file`, `get_top_level_key`, `get_instructions`) — replacing the private `CLIENT_META` const in `MCPClientsBlock`. Enumeration collapses to a single canonical entry point `AbstractMCPClient::get_all_registered_clients()` that fires the existing `acrossai_mcp_client_classes` filter, validates FQNs + slugs (`[a-z0-9-]{1,64}`), dedups + sorts — mirroring `ConnectorProfileRegistry::get_profiles()`. The glob-based `AbstractMCPClient::get_all_clients()` (which ignored the filter) is removed. Third-party client subclasses contributed via the filter now have a symmetric way to declare their own icon / description / config-file / top-level-key / instructions instead of being stranded in the Renderer's private const. Rendered output for the eight built-in clients on the server-edit → Clients tab is byte-identical. No breaking changes for existing third-party subclasses — the five new methods default to empty strings.
> ```
>
> ---
>
> **Definition of Done gates (per task + globally):**
>
> - Each TASK MUST leave PHPStan level 8 + PHPCS individually green before
>   moving to the next. Constitution §VII per-task gating applies.
> - `vendor/bin/phpunit --testsuite=mcpclients` MUST be green after TASK-1
>   through TASK-5; abilities suite MUST NOT regress.
> - Rendered HTML on the server-edit Clients tab MUST be byte-identical
>   before and after the refactor (verified by TASK-4's snapshot test + the
>   Manual Verification Checklist below).
> - Every `__()` call uses the `'acrossai-mcp-manager'` text domain
>   (grep gate: `grep -rEn "__\\(\\s*['\"][^'\"]+['\"](\\s*,\\s*['\"](?!acrossai-mcp-manager))" includes/MCPClients/`).
> - Zero remaining references to `CLIENT_META` or `AbstractMCPClient::get_all_clients` in the codebase.

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

### TASK-1 — `AbstractMCPClient` metadata methods
- [ ] `AbstractMCPClient` declares five new public methods: `get_icon()`, `get_description()`, `get_config_file()`, `get_top_level_key()`, `get_instructions()`.
- [ ] All five return `''` by default (empty-string signature) and are NOT abstract — a bare test-only subclass compiles + instantiates without implementing them.
- [ ] Existing three abstract methods (`get_client_slug`, `get_client_name`, `get_config_snippet`) unchanged in signature.

### TASK-2 — `CLIENT_META` migration into eight concrete clients
- [ ] Each of the eight concrete clients (`ClaudeDesktopClient`, `ClaudeCodeClient`, `VSCodeClient`, `GitHubCopilotClient`, `CodexClient`, `CursorClient`, `GeminiClient`, `CustomClient`) overrides all five metadata methods.
- [ ] Every override returns the exact string that was in `MCPClientsBlock::CLIENT_META[$slug]` for the corresponding key.
- [ ] `description` + `instructions` wrapped in `__()` with the `'acrossai-mcp-manager'` text domain; `config_file` + `top_level_key` + `icon` untranslated.
- [ ] Per-client unit tests assert each of the five getters returns the expected value.

### TASK-3 — Canonical enumeration + glob-based deletion
- [ ] `AbstractMCPClient::DEFAULT_CLIENT_CLASSES` is a `public const` listing the eight built-in FQNs in the same order as the pre-refactor `MCPClientsBlock` default array.
- [ ] `AbstractMCPClient::get_all_registered_clients()` exists as a `public static function`, returns `AbstractMCPClient[]` sorted by slug ascending.
- [ ] The method fires `acrossai_mcp_client_classes` with `DEFAULT_CLIENT_CLASSES` as the seed.
- [ ] Invalid FQNs (non-string, missing class, wrong parent) silently skipped.
- [ ] Invalid slugs (empty, regex-mismatched) trigger `_doing_it_wrong` under `WP_DEBUG` and are skipped.
- [ ] Duplicate slugs trigger `_doing_it_wrong` under `WP_DEBUG`; later contribution wins.
- [ ] Result sorted alphabetically by slug.
- [ ] `AbstractMCPClient::get_all_clients()` (glob-based) is deleted from the source tree.
- [ ] `tests/phpunit/MCPClients/GetAllRegisteredClientsTest.php` exists and covers the six enumeration test cases above.

### TASK-4 — `MCPClientsBlock` rewire + `CLIENT_META` deletion
- [ ] `MCPClientsBlock::render_body()` calls `AbstractMCPClient::get_all_registered_clients()` instead of hand-rolling the enumeration.
- [ ] Every `CLIENT_META[$slug]['...']` lookup replaced with the corresponding client-instance method call.
- [ ] `MCPClientsBlock::CLIENT_META` const is deleted.
- [ ] The inline default-classes array is deleted from `MCPClientsBlock::render_body()`.
- [ ] `MCPClientsBlock::slug()` still returns `'clients'`; `instance()` singleton preserved; constructor untouched.
- [ ] Render snapshot regression test asserts identical output for at least one representative client (`claude-desktop`) before + after the refactor.

### TASK-5 — Grep audit
- [ ] `grep -rEn 'CLIENT_META' --include='*.php' includes/ admin/ public/ tests/` returns zero hits outside test fixtures.
- [ ] `grep -rEn 'get_all_clients\(\)' --include='*.php' includes/ admin/ public/ tests/` returns zero hits.
- [ ] `grep -rEn 'acrossai_mcp_client_classes' --include='*.php' includes/ admin/ public/ tests/` — every hit is inside `AbstractMCPClient::get_all_registered_clients()`, a test fixture, or an mu-plugin registering a legitimate third-party contribution.
- [ ] No files outside `includes/MCPClients/` + `public/Renderers/MCPClientsBlock.php` + `tests/phpunit/MCPClients/` + `tests/phpunit/Public/Renderers/` are modified in this feature.

### TASK-6 — Memory hygiene
- [ ] Any pre-existing `DECISIONS.md` entries referencing `CLIENT_META` or `get_all_clients` marked Superseded (Feature 035), original body preserved per PATTERN-MEMORY-SUPERSESSION-VS-ANNOTATION.
- [ ] New decision entry (self-contained-subsystem-contract pattern) added to `DECISIONS.md` with matching `INDEX.md` row.
- [ ] `README.txt` `= Unreleased =` changelog contains the F035 bullet (create the Unreleased section if it doesn't exist yet).

### Render-parity check (blocker before merge)
- [ ] Load a local install's server-edit → Clients tab pre-refactor. Screenshot the sub-nav pills + each of Claude Desktop / Codex / Custom Client's config panels (the three snippet-shape codepaths: JSON, TOML, "depends on your client").
- [ ] Load the same after the refactor. Screenshot the same three panels.
- [ ] Manual DOM diff (or literal `diff -u` on saved HTML): rendered output is identical.

### Third-party filter smoke test
- [ ] Add an mu-plugin registering a fake `AbstractMCPClient` subclass with distinct slug + icon + description via `acrossai_mcp_client_classes`.
- [ ] Verify the fake client appears in the sub-nav with its declared metadata (icon + description surfacing from the getter methods, not from any const).
- [ ] Remove the mu-plugin; verify the fake client disappears cleanly with no residual state.

### Quality gates (all must be green before commit)
- [ ] PHPStan level 8 — zero errors on all touched files.
- [ ] PHPCS — zero errors on all touched files.
- [ ] `composer test` — full PHPUnit suite green (mcpclients + abilities + oauth + rest + all others).
- [ ] `composer dump-autoload` — succeeds with zero warnings.
- [ ] CI green on all 8 required checks (WPCS, PHPStan, PHPUnit pure + integration, ESLint, PHP 8.1+ compat, Package Hierarchy, F021 grep gates).

---

## Related work (context, not scope)

- **F033** (`docs/planings-tasks/033-f030-permission-callback-wrapper-fix.md`) — the F030 wrapper security fix, shipped in 0.1.7. Referenced here only for the memory-supersession pattern precedent.
- **F034** (proposed — GitHub [issue #46](https://github.com/acrossai-co/acrossai-mcp-manager/issues/46)) — filter-time eligibility gate refactor for `PermissionOverrideProcessor`. Unrelated subsystem; independent branch when it ships.
- **F036** (planned — no brief yet) — Public connection-method discovery API (`ConnectionMethodRegistry` under `public/Discovery/`). Motivating consumer for F035: F036 needs a canonical single enumeration path with fully-populated per-item metadata, which F035 establishes. F036 briefing happens after F035 lands on `main`.
