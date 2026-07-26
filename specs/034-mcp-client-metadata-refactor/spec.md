# Feature Specification: MCP Client Metadata + Filter-Aware Enumeration Refactor

**Feature Branch**: `034-mcp-client-metadata-refactor`
**Created**: 2026-07-25
**Status**: Draft
**Input**: User description: Refactor the MCP client subsystem to align with the `AbstractConnectorProfile` / `ConnectorProfileRegistry` shape already used for AI connector profiles. Every concrete MCP client class becomes self-describing (icon, description, config file, top-level key, instructions declared as methods on the class), enumeration collapses to a single canonical filter-aware entry point, and the display Renderer stops carrying display metadata in a private constant. See `docs/planings-tasks/035-mcp-client-metadata-refactor.md` for the full engineering brief (note: brief filename says 035 due to prior planning iteration; spec dir and branch use 034 per next-sequential numbering — no functional impact, resolve at merge if desired).

## Clarifications

### Session 2026-07-25

- Q: How should the sub-nav ordering work — FR-010 (sort by slug ascending) directly contradicts FR-016 (byte-identical render) since the pre-refactor order is insertion order, not alphabetical. → A: Add a `get_priority(): int` method on `AbstractMCPClient` (WP-idiomatic pattern — default 100, lower runs earlier). Each built-in client overrides it to return its current slot number (10, 20, 30, ..., 80) so the visual order is byte-identical to the pre-refactor state. Third-party contributions default to priority 100 (sort AFTER built-ins), with slug ascending as the tiebreaker for same-priority entries. Both FR-010 and FR-016 hold under this model — no relaxation needed. Mirrors the `admin/Partials/ServerTabs/Registry` pattern already used elsewhere in this plugin.
- Q: What version tag should `_doing_it_wrong()` calls use for the invalid-slug + duplicate-slug checks added in FR-008 / FR-009? → A: `'0.1.7'` — the current release version on `main` when the F034 fix merges. Matches the plugin's `Version:` header at merge time; avoids version-tag churn if the release version changes before shipping. Consistent with WP core convention where `_doing_it_wrong` cites the version that first shipped the constraint.

## User Scenarios & Testing *(mandatory)*

### User Story 1 — Third-party plugin adds a self-describing MCP client (Priority: P1)

A companion plugin developer writes a new subclass of `AbstractMCPClient` for a new AI editor (say "Zed") and registers it via the existing `acrossai_mcp_client_classes` filter. Their subclass declares its own icon, description, config file path, JSON top-level key, setup instructions, and — optionally — its preferred sub-nav slot via `get_priority()`. Six method overrides on the abstract base class — the same shape a companion plugin already uses today for AI connector profiles (`AbstractConnectorProfile`). By default (no `get_priority()` override), the new client appears after all built-ins, sorted alphabetically with any other default-priority third-party contributions. Developers who want a specific slot (before/between/after built-ins) override `get_priority()` to return their target integer. The new client appears in the server-edit → Clients tab sub-nav with the companion plugin's declared metadata and in the declared position.

**Why this priority**: This is the primary user-visible improvement. Before this refactor, third-party client subclasses appeared in the sub-nav but had empty display metadata because the metadata source (`MCPClientsBlock::CLIENT_META` — a private constant on the display Renderer) only contained entries for the eight base plugin's built-in clients. Third-party clients had no seam to declare their own metadata, which effectively made the extension filter half-broken for any consumer that wanted proper visual integration.

**Independent Test**: Register an `mu-plugin` that (a) declares a class extending `AbstractMCPClient` with the three original abstract methods AND the five new metadata methods, and (b) hooks `acrossai_mcp_client_classes` to append the class FQN. Navigate to the server-edit → Clients tab. Verify the new client appears in the sub-nav with the mu-plugin's declared icon + description, and its dedicated panel renders the declared config file path + top-level key + instructions — not empty strings, not defaults.

**Acceptance Scenarios**:

1. **Given** an mu-plugin registers a valid `AbstractMCPClient` subclass overriding all five string metadata methods (and optionally `get_priority()`) and hooks `acrossai_mcp_client_classes`, **When** an administrator loads the server-edit → Clients tab, **Then** the sub-nav shows the new client's icon + name in the position determined by its priority (default 100 → after built-ins; explicit override → the declared slot), and the dedicated panel renders every metadata field the subclass declared.
2. **Given** an mu-plugin registers a valid `AbstractMCPClient` subclass overriding only the three original abstract methods (no metadata or priority overrides), **When** an administrator loads the server-edit → Clients tab, **Then** the client still appears in the sub-nav after all built-ins (priority 100, sorted alphabetically among any peers at same priority), and the dedicated panel renders empty strings for icon / description / config file / top-level key / instructions — the base class defaults.
3. **Given** the mu-plugin is removed, **When** the administrator loads the server-edit → Clients tab, **Then** only the eight built-in clients appear in the pre-refactor visual order (`ClaudeDesktop, ClaudeCode, VSCode, GitHubCopilot, Codex, Cursor, Gemini, Custom` — enforced by their assigned priorities 10-80), with byte-identical rendering to the pre-refactor state.

---

### User Story 2 — Site administrator sees byte-identical rendering (Priority: P1)

A site administrator opens the server-edit → Clients tab both before and after this refactor ships. The sub-nav shows the same eight clients in the same order with the same icons + names. Each client's dedicated panel shows the same JSON/TOML snippet, the same config file path, the same top-level key label, and the same setup instructions — down to the exact characters. No admin-visible behaviour changes at all.

**Why this priority**: Correctness invariant. This refactor is a plumbing change; any visible regression would signal a bug in the migration (either a metadata value was mis-copied or a lookup path is broken). Byte-identity is the observable outcome that proves the migration is faithful.

**Independent Test**: Screenshot or DOM-diff the rendered Clients tab pre-refactor and post-refactor. Compare all eight client panels. Every visible string, emoji, and layout element MUST match.

**Acceptance Scenarios**:

1. **Given** the plugin is on the pre-refactor code, **When** an admin loads the Clients tab and captures the DOM, **Then** the captured DOM equals byte-for-byte the DOM captured after the refactor for all eight built-in clients (Claude Desktop, Claude Code, VS Code, GitHub Copilot, Codex, Cursor, Gemini, Custom).
2. **Given** the refactor is live, **When** an admin generates + copies an Application Password from any client's panel and pastes it into their local config file, **Then** the copy-paste flow works identically to pre-refactor state (config snippet format unchanged; password token substitution unchanged).

---

### User Story 3 — Plugin maintainer reads one enumeration path, not three (Priority: P2)

A maintainer investigating "which MCP clients are registered?" finds a single canonical answer: `AbstractMCPClient::get_all_registered_clients()`. Before this refactor, three competing paths existed and disagreed with each other:

- `AbstractMCPClient::get_all_clients()` — globbed the module directory and ignored the filter (third-party clients invisible here).
- `MCPClientsBlock::render_body()` — inline default array + filter loop (only place that was filter-aware, but the enumeration logic was buried in a private render method).
- `MCPClientsBlock::CLIENT_META` — private const of display metadata, keyed by slug, with zero entries for third-party clients.

After this refactor, all three converge into one method on the abstract base class that mirrors `ConnectorProfileRegistry::get_profiles()` in shape and validation semantics.

**Why this priority**: Reduces onboarding cost and eliminates the class of drift bug that motivated this feature — no maintainer will ever again ask "why does `get_all_clients()` return a different list than the render loop?" because there is only one method.

**Independent Test**: `grep -rEn 'get_all_clients\(\)' includes/ admin/ public/ tests/` returns zero hits post-refactor. `grep -rEn 'CLIENT_META' includes/ admin/ public/ tests/` returns zero hits post-refactor. `grep -rEn 'get_all_registered_clients' includes/ admin/ public/ tests/` returns hits for every caller that previously used either of the two deprecated paths.

**Acceptance Scenarios**:

1. **Given** a maintainer wants to know "which clients are exposed for this server request", **When** they grep the codebase for the enumeration entry point, **Then** they find exactly one: `AbstractMCPClient::get_all_registered_clients()` — no glob-based fallback path, no inline render-loop enumeration.
2. **Given** a maintainer wants to know "where does client X's icon come from" (or its sub-nav position), **When** they open the concrete client class file (`includes/MCPClients/XClient.php`), **Then** the answer is right there in the class — `get_icon(): string` returns the value, `get_priority(): int` returns the slot. They never need to open the Renderer to find display metadata or ordering rules.

---

### Edge Cases

- **Third-party subclass registered without any metadata overrides**: Displays with empty strings for icon / description / config file / top-level key / instructions AND with default priority 100 (sorted after built-ins). Renders without fatal, without warning; the empty fields are visually blank but the sub-nav entry + config snippet still function.
- **Multiple third-party subclasses at default priority 100**: They sort among themselves alphabetically by slug (the tiebreaker rule in FR-010). Stable, testable, no last-registered-wins surprise.
- **Third-party subclass returns an invalid slug** (empty string, uppercase letters, underscore, longer than 64 chars): Silently skipped from the enumeration. Under `WP_DEBUG`, `_doing_it_wrong()` fires so developers see the mistake in their debug log.
- **Two different subclasses return the same slug** (e.g., two competing "cursor" implementations): The later contribution wins the sub-nav slot. Under `WP_DEBUG`, `_doing_it_wrong()` fires so developers know their contribution was overridden or was overriding.
- **Third-party contribution is a non-string, or a class-string that doesn't exist, or a class that doesn't extend `AbstractMCPClient`**: Silently skipped from the enumeration (preserves the existing SEC-013-008 behaviour where invalid FQNs never fatal a request).
- **`AbstractMCPClient::get_all_registered_clients()` called during a request where no MCP context is active** (WP-CLI, cron, direct call from a script): Returns the built-in eight + any filter-contributed valid clients. The method does not require request context or admin capability.

---

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: `AbstractMCPClient` MUST expose six new public methods — `get_icon()`, `get_description()`, `get_config_file()`, `get_top_level_key()`, `get_instructions()` (each returning `string`, defaulting to `''`), plus `get_priority()` (returning `int`, defaulting to `100`).
- **FR-002**: The six new methods MUST be non-abstract so any existing external subclass compiles and runs unchanged. Adding this abstract-level contract MUST NOT break third-party subclasses that only implement the three original abstract methods (`get_client_slug`, `get_client_name`, `get_config_snippet`).
- **FR-003**: Each of the eight built-in concrete client classes (`ClaudeDesktopClient`, `ClaudeCodeClient`, `VSCodeClient`, `GitHubCopilotClient`, `CodexClient`, `CursorClient`, `GeminiClient`, `CustomClient`) MUST override all five string metadata methods to return the exact values currently in `MCPClientsBlock::CLIENT_META[$slug]` AND override `get_priority()` to return its slot number in the current visual order: `ClaudeDesktopClient=10`, `ClaudeCodeClient=20`, `VSCodeClient=30`, `GitHubCopilotClient=40`, `CodexClient=50`, `CursorClient=60`, `GeminiClient=70`, `CustomClient=80`.
- **FR-004**: `description` and `instructions` overrides MUST wrap their returned strings in `__()` with text domain `'acrossai-mcp-manager'`. `icon` (emoji), `config_file` (path), and `top_level_key` MUST remain untranslated (technical strings, not UI copy).
- **FR-005**: `AbstractMCPClient` MUST expose a single canonical static enumeration method `get_all_registered_clients(): array` returning `AbstractMCPClient[]`. This is the ONE method every caller uses to discover which clients are registered.
- **FR-006**: `get_all_registered_clients()` MUST fire the existing `acrossai_mcp_client_classes` filter exactly once per call, seeded with a class-level constant `DEFAULT_CLIENT_CLASSES` listing the eight built-in FQNs in a stable order.
- **FR-007**: `get_all_registered_clients()` MUST validate each contributed entry: skip non-strings, skip strings that fail `class_exists`, skip classes that don't extend `AbstractMCPClient`. Invalid FQN-shape entries are silently skipped (matches current `MCPClientsBlock::render_body` behaviour per SEC-013-008).
- **FR-008**: `get_all_registered_clients()` MUST validate each contributed subclass's `get_client_slug()` against the regex `/\A[a-z0-9-]{1,64}\z/`. Invalid slugs MUST trigger `_doing_it_wrong( 'AcrossAI_MCP_Manager\\Includes\\MCPClients\\AbstractMCPClient::get_all_registered_clients', <translated message>, '0.1.7' )` under `WP_DEBUG` and be skipped from the returned list.
- **FR-009**: `get_all_registered_clients()` MUST dedup by slug. When two contributions share the same slug, the later contribution wins the slot and `_doing_it_wrong( ... , '0.1.7' )` fires under `WP_DEBUG` with the same function-name shape as FR-008.
- **FR-010**: The returned list MUST be sorted by `get_priority()` ascending, with `get_client_slug()` ascending as the tiebreaker for entries sharing the same priority. This produces a byte-identical sub-nav order for the eight built-ins (whose priorities are pre-assigned per FR-003) and a stable, developer-controllable slot for third-party contributions (default priority 100 → appended after built-ins; explicit `get_priority()` overrides let contributors interleave with built-ins or reserve early slots deliberately).
- **FR-011**: The pre-existing `AbstractMCPClient::get_all_clients()` glob-based enumeration method MUST be removed. Every caller migrates to `get_all_registered_clients()` in the same feature.
- **FR-012**: The pre-existing `MCPClientsBlock::CLIENT_META` private const MUST be removed. Every caller migrates to reading metadata via the corresponding method on each client instance.
- **FR-013**: `MCPClientsBlock::render_body()` MUST source its client list from `AbstractMCPClient::get_all_registered_clients()` — no inline default-classes array, no local filter loop.
- **FR-014**: The public `acrossai_mcp_client_classes` filter contract MUST be preserved verbatim: still accepts an array of FQN strings, still applies with the same default eight classes, still skips invalid entries silently.
- **FR-015**: The existing three abstract method signatures on `AbstractMCPClient` (`get_client_slug`, `get_client_name`, `get_config_snippet`) MUST NOT change. The five existing protected helpers (`build_server_url`, `derive_server_key`, `safe_token`, `current_username`, `redact_token`) MUST NOT change. The two existing constants (`EMPTY_TOKEN_PLACEHOLDER`, `SERVER_KEY_FALLBACK`) MUST NOT change.
- **FR-016**: Rendered HTML output for all eight built-in clients on the server-edit → Clients tab MUST be byte-identical before and after this refactor. Verified via a snapshot / DOM-diff regression test for at least one representative client.
- **FR-017**: No files outside `includes/MCPClients/` and `public/Renderers/MCPClientsBlock.php` MUST be modified by this feature (excluding the test files under `tests/phpunit/MCPClients/` + `tests/phpunit/Public/Renderers/` and the memory/docs files noted in Success Criteria).
- **FR-018**: `get_priority()` returns `int`. Lower values sort earlier (WP-idiomatic; matches `add_action` priority semantics and the `admin/Partials/ServerTabs/Registry` tab ordering pattern). Default value is `100` — third-party contributions without an explicit override sort after all eight built-ins (which use 10, 20, 30, …, 80). Priority values are NOT validated (any integer, including negative, is accepted); developers are trusted to pick sensible slots. `get_priority()` is called by `get_all_registered_clients()` during the sort phase, after slug validation and dedup but before `ksort`.

### WordPress Requirements

**PHP Version**: PHP 8.1+ (plugin minimum, per AGENTS.md).
**WordPress Version**: 6.9+ (plugin minimum, per AGENTS.md).
**Multisite**: Single-site (plugin is single-site per AGENTS.md; unchanged by this feature).
**Required Plugins / Packages**: None — this is a purely internal refactor.
**Optional Integrations**: None new. The existing `acrossai_mcp_client_classes` extension filter is honoured by companion plugins that contribute their own `AbstractMCPClient` subclasses; no change to that contract.

### Module Placement

**PHP Class(es)**:
- `includes/MCPClients/AbstractMCPClient.php` → namespace `AcrossAI_MCP_Manager\Includes\MCPClients` — abstract base class; five new methods added, one method deleted (`get_all_clients()`), one new static method added (`get_all_registered_clients()`), one new class constant added (`DEFAULT_CLIENT_CLASSES`).
- `includes/MCPClients/{ClaudeDesktop,ClaudeCode,VSCode,GitHubCopilot,Codex,Cursor,Gemini,Custom}Client.php` → each gets five metadata method overrides.
- `public/Renderers/MCPClientsBlock.php` → namespace `AcrossAI_MCP_Manager\Public\Renderers` — inline enumeration replaced with `AbstractMCPClient::get_all_registered_clients()` call; `CLIENT_META` const deleted; `CLIENT_META[$slug][...]` lookups replaced with client-instance method calls.

**Hook Registration**: This feature adds no new hooks. The pre-existing `acrossai_mcp_client_classes` filter continues to fire from `AbstractMCPClient::get_all_registered_clients()` (moved from `MCPClientsBlock::render_body()` where it fires today). No `add_action` / `add_filter` calls are added or moved in `Main.php`.

### Admin UI Requirements

No new admin UI. The Clients tab render pipeline is unchanged from the site administrator's perspective — the refactor is purely how metadata flows from data source to renderer.

### REST API Contract

No new REST routes. No changes to existing routes.

### Database / Storage

**No persistent storage**: N/A. This feature is a source-code refactor with zero database schema impact, zero WP option reads or writes, and zero migration.

### Security Checklist

*(Derived from Constitution §III — verified where applicable to this feature)*

- [x] All form/AJAX handlers verify nonce — N/A (no forms, no AJAX).
- [x] All admin page renders check capability — N/A (no new admin pages).
- [x] All REST routes have explicit `permission_callback` — N/A (no REST changes).
- [x] All user input sanitized at system boundary — N/A (no user input handling; the class-FQN contribution filter is an admin-level developer contract).
- [x] All output escaped at point of rendering — verified via FR-016 render-parity: existing escaping in `MCPClientsBlock` render helpers is unchanged.
- [x] All DB queries use `$wpdb->prepare()` — N/A (no DB queries).
- [x] OAuth tokens / Application Passwords stored hashed — N/A (no token handling).
- [x] File uploads validated — N/A (no file uploads).

### Key Entities

- **MCP Client** — a self-describing configuration definition for one AI tool (Claude Desktop, Cursor, etc.). Post-refactor, every client owns its own display metadata (icon, description, config file, top-level key, instructions) AND its own sub-nav slot preference (`get_priority()`) via method overrides on the base class. Slug is the stable machine identifier (kebab-case, `[a-z0-9-]{1,64}`). Name is the human-readable display name. Priority is an integer sort key (lower = earlier; default 100).

---

## Success Criteria *(mandatory)*

### Definition of Done Gates

All of the following MUST pass before this feature is considered complete:

- [ ] PHPCS validation: zero errors and zero warnings on all touched files (`vendor/bin/phpcs`).
- [ ] PHPStan level 8: zero errors on all touched files (`vendor/bin/phpstan`).
- [ ] ESLint: N/A (no JS added).
- [ ] PHPUnit tests written and passing for: metadata-method defaults on the abstract, metadata-method overrides on each of the eight concrete clients, canonical enumeration behaviour (default state, filter-added contribution, invalid FQN skip, invalid slug reject, duplicate-slug later-wins), and render byte-identity for at least one representative client.
- [ ] `vendor/bin/phpunit --testsuite=mcpclients` green.
- [ ] Security checklist above: all applicable items verified.
- [ ] All hooks wired in `Main.php` — N/A (no hook changes).
- [ ] All new admin UI uses DataForm/DataViews — N/A (no new admin UI).
- [ ] No code duplication — the shared "list all clients" logic is now in ONE place (previously two + one metadata source).
- [ ] All functions, hooks, and classes prefixed with `acrossai_mcp_` — verified.
- [ ] `npm run validate-packages` passes.

### Measurable Outcomes

- **SC-001**: A third-party plugin author can add a new MCP client that appears in the sub-nav with a distinct icon + description + config-file path + top-level key + instructions + declared position, using ONLY method overrides on `AbstractMCPClient` (no PR to the base plugin, no modification to `MCPClientsBlock::CLIENT_META`, no filter beyond `acrossai_mcp_client_classes`).
- **SC-002**: `grep -rEn 'CLIENT_META' includes/ admin/ public/ tests/` returns exactly zero hits post-refactor.
- **SC-003**: `grep -rEn 'get_all_clients\(\)' includes/ admin/ public/ tests/` returns exactly zero hits post-refactor.
- **SC-004**: `grep -rEn 'acrossai_mcp_client_classes' includes/ admin/ public/ tests/` returns hits ONLY inside `AbstractMCPClient::get_all_registered_clients()` and test fixtures — nowhere else.
- **SC-005**: Rendered DOM for all eight built-in clients on the Clients tab is byte-identical to the pre-refactor state (measured via automated snapshot regression test for at least one representative client + manual DOM-diff for the other seven).
- **SC-006**: A grep-gate on the abilities test suite AND the mcpclients test suite returns green post-refactor: `vendor/bin/phpunit --testsuite=mcpclients` + `vendor/bin/phpunit --testsuite=abilities` — no cross-suite regression.

---

## Assumptions

- Third-party plugins that currently extend `AbstractMCPClient` only implement the three original abstract methods and do NOT rely on `AbstractMCPClient::get_all_clients()` being present. If any external code depends on the glob-based enumeration path, this refactor is a breaking change for that caller (mitigation: the two paths returned different sets already, so no consumer could have depended on both — but a consumer using the glob path exclusively would need to migrate to `get_all_registered_clients()`).
- The `acrossai_mcp_client_classes` filter is treated as a public, versioned extension contract; its shape (array of FQN strings, invalid FQNs silently skipped) is preserved verbatim.
- WordPress `WP_DEBUG` semantics apply: `_doing_it_wrong()` fires only when `WP_DEBUG === true`; in production installs, invalid slugs / duplicate slugs / other developer mistakes are silently skipped without user-visible side effects.
- Text domain `'acrossai-mcp-manager'` is available for `__()` calls on the eight concrete client subclasses (matches the plugin-wide domain per AGENTS.md).
- Render byte-identity is verified against a fixed representative fixture (e.g., a seeded test MCP server row) — the test does NOT assert against runtime-dynamic strings (Application Password, timestamps) which would produce non-deterministic snapshots.
- No admin-UI tests exist today that would need to be updated for the refactor; the existing sub-nav + panel rendering paths in `MCPClientsBlock` retain their private-helper signatures.
