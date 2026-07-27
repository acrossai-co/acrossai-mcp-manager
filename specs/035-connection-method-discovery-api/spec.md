# Feature Specification: Public Connection-Method Discovery API

**Feature Branch**: `035-connection-method-discovery-api`
**Created**: 2026-07-26
**Status**: Draft
**Input**: User description: Add a public discovery class `ConnectionMethodRegistry` under `public/Discovery/` (namespace `AcrossAI_MCP_Manager\Public\Discovery`) that exposes every registered NPM method, MCP client, and AI connector as a unified list of plain-associative-array DTOs. Consumers: third-party plugins (specifically a planned BuddyBoss add-on) that need to build per-server allowlist admin UIs and render frontend connection surfaces. Class is `@experimental` per DEC-CLIENT-RENDERER-PUBLIC-API. Singleton per A2. Public methods: `instance()`, `get_all()` returning array with keys `'npm' / 'clients' / 'ai_connectors'`, `get_npm_methods()`, `get_clients()` (delegates to F034 `AbstractMCPClient::get_all_registered_clients()`), `get_ai_connectors()` (delegates to `ConnectorProfileRegistry::instance()->get_profiles()`), `find( string $category, string $slug ): ?array`. Two new filters: `acrossai_mcp_npm_methods` (seeded with built-in NPM DTO) and `acrossai_mcp_connection_methods` (fires once on the assembled `get_all()` result). Reuse existing utilities via delegation — never re-fire the underlying extension seams (`acrossai_mcp_client_classes`, `acrossai_mcp_manager_connector_profiles`). Introduce one new static helper `NpmClientBlock::get_default_npm_method(): array` so the built-in NPM template + option gate has a single source of truth. Register a new PHPUnit suite `discovery` under `tests/phpunit/Public/Discovery/` plus a matching CI job. See `docs/planings-tasks/036-connection-method-discovery-api.md` for the full brief (note: brief filename says F036, spec dir uses `035-` per next-sequential numbering after F034 — same pattern as the earlier F035/F034 divergence, no functional impact).

## Clarifications

### Session 2026-07-26

- Q: If a companion plugin appends an NPM DTO via `acrossai_mcp_npm_methods` with a slug that collides with the built-in `npx-acrossai-mcp-manager`, what does `get_npm_methods()` return? → A: Silent later-wins dedup by slug — matches F034's `get_all_registered_clients()` semantic so all three categories behave the same way.
- Q: How should `get_npm_methods()` handle malformed filter contributions (missing required top-level keys)? → A: Silently drop invalid entries + `_doing_it_wrong` under `WP_DEBUG` — matches F034's SEC-013-008 pattern and F021's `ConnectorProfileRegistry` behaviour.
- Q: If a callback on `acrossai_mcp_connection_methods` returns something invalid (not an array, or missing the three required category keys), how should `get_all()` behave? → A: Fall back to the pre-filter assembled result + `_doing_it_wrong` under `WP_DEBUG` — both new filters (`acrossai_mcp_npm_methods` and `acrossai_mcp_connection_methods`) fail identically per SEC-013-008.
- Plan-review SEC-035-001 (2026-07-26 security-review): FR-009b tightened from key-only validation to key + value-type validation — a malicious filter contribution with `slug => array()` / `meta => 'string'` passed the original gate but would break downstream consumers. Type check MUST verify 5 string values + `meta` is `is_array()`.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Third-party plugin enumerates all connection methods in one call (Priority: P1)

A companion plugin developer building a BuddyBoss add-on needs to know every connection method the site supports so they can render an allowlist admin UI ("check which methods to expose to members of role X"). Today they would have to make three separate calls with three different shapes: `ConnectorProfileRegistry::instance()->get_profiles()` for AI connectors, re-implement `MCPClientsBlock`'s filter loop for MCP clients, and read the `acrossai_mcp_npm_login_enabled` option manually for NPM. Post-F035, one call — `ConnectionMethodRegistry::instance()->get_all()` — returns a unified three-category array of plain associative arrays safe to persist in their own database.

**Why this priority**: This is the whole reason the feature exists. The BuddyBoss add-on cannot ship without it. Every other user story flows from this one.

**Independent Test**: Call `ConnectionMethodRegistry::instance()->get_all()` from an mu-plugin. Assert the return value is an array with exactly three keys (`npm`, `clients`, `ai_connectors`), each mapping to an array of DTOs. Serialize each DTO with `wp_json_encode()` — round-trip MUST succeed (proves plain-associative-array shape safe for third-party DB storage).

**Acceptance Scenarios**:

1. **Given** a fresh site with F035 shipped and no companion plugins active, **When** a third-party plugin calls `ConnectionMethodRegistry::instance()->get_all()`, **Then** the return value is `[ 'npm' => array[…], 'clients' => array[…], 'ai_connectors' => array[…] ]` where `clients` contains exactly 8 DTOs (the F034 built-ins) and each DTO carries the six top-level keys.
2. **Given** a companion plugin has registered a valid `AbstractConnectorProfile` subclass via `acrossai_mcp_manager_connector_profiles`, **When** `get_ai_connectors()` is called, **Then** the returned list contains the companion's connector with `slug`, `name`, and `icon` populated from the profile's own methods AND `meta.class` set to the profile's FQN.
3. **Given** a third-party plugin wants a specific method quickly, **When** they call `find( 'client', 'claude-desktop' )`, **Then** the return is the same DTO shape as `get_clients()[N]` OR `null` if not found — no `WP_Error`, no exception, no partial object.

---

### User Story 2 - NPM becomes extensible via filter (Priority: P2)

A companion plugin developer wants to add a second NPM bridge (for example, a pnpm variant, or a yarn-based alternative to the default npx command) as a connection method their users can pick. Today NPM is a hardcoded template inside `NpmClientBlock` with no extension seam. Post-F035, they hook `acrossai_mcp_npm_methods` and append their own DTO — the discovery API returns their contribution alongside the built-in npx bridge.

**Why this priority**: NPM extensibility is a symmetric completeness — the client and AI-connector subsystems already have filter-based extension; NPM had a gap. F035 closes it. Not blocking the BuddyBoss add-on directly (the initial add-on only needs the built-in npx bridge) but establishes the symmetric contract for future NPM variants.

**Independent Test**: Register an mu-plugin that hooks `acrossai_mcp_npm_methods` to append a fake NPM DTO (slug `test-yarn`). Call `get_npm_methods()`. Assert the return contains 2 items: the default plus the fake, in filter order.

**Acceptance Scenarios**:

1. **Given** no filter is registered, **When** `get_npm_methods()` is called, **Then** the return contains exactly 1 item — the default `npx-acrossai-mcp-manager` DTO with `meta.command_template` set to the npx bridge command and `meta.enabled_option` set to `acrossai_mcp_npm_login_enabled`.
2. **Given** a companion plugin appends a fake NPM DTO via `acrossai_mcp_npm_methods`, **When** `get_npm_methods()` is called, **Then** the return contains both items in the order the filter produced.
3. **Given** the same filter is registered, **When** `get_all()` is called, **Then** `['npm']` reflects the filter output — proving the filter fires inside `get_npm_methods()` and is not bypassed by `get_all()`.

---

### User Story 3 - Cross-category filter enables holistic customization (Priority: P3)

A companion plugin wants to remove all NPM methods AND one specific AI connector from the discovery output entirely (for example, because their deployment does not allow local CLI bridges). Post-F035, they hook a single `acrossai_mcp_connection_methods` filter that fires once on the assembled `get_all()` result — one filter, one edit, all three categories in scope. The alternative would be three separate filter registrations against `acrossai_mcp_npm_methods` + `acrossai_mcp_client_classes` + `acrossai_mcp_manager_connector_profiles` — more verbose, three code paths to maintain.

**Why this priority**: Convenience API for the ~5% of consumers who need cross-category concerns. The base plugin's canonical registries remain the authoritative sources; this filter is a curation layer on top. Not blocking the BuddyBoss add-on.

**Independent Test**: Register an mu-plugin that hooks `acrossai_mcp_connection_methods` to remove the `npm` category entirely. Assert `get_all()['npm']` is empty; `clients` and `ai_connectors` are unchanged.

**Acceptance Scenarios**:

1. **Given** a filter removes the `clients` category from the assembled result, **When** `get_all()` is called, **Then** `['clients']` is empty; `['npm']` and `['ai_connectors']` are unchanged.
2. **Given** the same filter is registered, **When** `get_clients()` is called directly (not via `get_all()`), **Then** the return is unchanged — the cross-category filter fires ONLY inside `get_all()`, not on per-category getters.

---

### Edge Cases

- **Zero AI connectors registered**: fresh install with no companion plugins active → `get_ai_connectors()` returns `[]`; `get_all()['ai_connectors']` is `[]`. No error.
- **Invalid subclass contributed via `acrossai_mcp_client_classes`**: F034's `AbstractMCPClient::get_all_registered_clients()` already silently skips per SEC-013-008. F035 inherits this via delegation — the invalid entry never reaches the DTO map.
- **Invalid connector profile contributed via `acrossai_mcp_manager_connector_profiles`**: `ConnectorProfileRegistry::get_profiles()` already validates + rejects invalid entries with `_doing_it_wrong` under `WP_DEBUG`. F035 inherits via delegation.
- **`acrossai_mcp_npm_methods` filter returns non-array or malformed DTO**: F035's `get_npm_methods()` MUST NOT crash; it accepts whatever the filter returns as long as it is an array. Malformed entries (missing keys) surface downstream at the consumer's own risk — F035's contract is "delegate, don't second-guess," matching the trust boundary of the existing extension seams.
- **`find( 'client', 'nonexistent' )` or `find( 'bogus-category', 'anything' )`**: returns `null`. No error, no fallback lookup, no fuzzy match.
- **`get_all()` called during a request where no MCP context is active** (WP-CLI, cron, direct call from a script): returns the same shape as an admin request. The registry does not require request context.

---

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The plugin MUST expose a class `ConnectionMethodRegistry` under `public/Discovery/` with namespace `AcrossAI_MCP_Manager\Public\Discovery`, marked `@experimental` per DEC-CLIENT-RENDERER-PUBLIC-API (contract freezes at plugin 1.0.0).
- **FR-002**: `ConnectionMethodRegistry` MUST be a singleton per A2 with a `public static function instance(): self` accessor and a `private __construct()`.
- **FR-003**: `ConnectionMethodRegistry` MUST expose exactly six public methods: `instance()`, `get_all()`, `get_npm_methods()`, `get_clients()`, `get_ai_connectors()`, and `find( string $category, string $slug ): ?array`. No additional public methods (avoids scope creep).
- **FR-004**: `get_all()` MUST return an array with exactly three keys: `'npm'`, `'clients'`, `'ai_connectors'` — each mapping to an array of DTOs. Missing categories are represented by empty arrays, never absent keys.
- **FR-005**: `get_all()` MUST memoize the assembled result per-request (single call per admin request, not per lookup).
- **FR-006**: Every DTO across every category MUST have the same six top-level keys: `category` (string, one of `'npm'` / `'client'` / `'ai_connector'`), `slug` (string, stable machine identifier), `name` (string, translated display name), `description` (string, translated one-liner; may be empty), `icon` (string — emoji for clients, URL for AI connectors, empty for NPM), `meta` (array, category-specific extras).
- **FR-007**: DTO `meta` field contents by category:
  - **npm**: `command_template` (string, e.g. `npx -y @acrossai/mcp-manager --siteurl=%s --server=%s`), `enabled_option` (string, the WP option name that gates the method — `acrossai_mcp_npm_login_enabled`)
  - **client**: `config_file` (string), `top_level_key` (string), `class` (string, FQN of the source `AbstractMCPClient` subclass)
  - **ai_connector**: `icon_url` (string, mirror of top-level `icon`), `has_redirect_whitelist` (bool), `class` (string, FQN of the source `AbstractConnectorProfile` subclass)
- **FR-008**: Every DTO MUST be a plain associative array (not an object). `wp_json_encode()` round-trip on the DTO MUST succeed without loss — safe for third-party DB persistence.
- **FR-009**: `get_npm_methods()` MUST fire a new filter `acrossai_mcp_npm_methods` exactly once per call, seeded with the base plugin's default NPM DTO. Consumers may append or replace entries.
- **FR-009a**: After the filter fires, `get_npm_methods()` MUST dedup contributions by `slug` using later-wins semantics — the last DTO with a given slug survives; earlier collisions are silently dropped. Mirrors F034's `AbstractMCPClient::get_all_registered_clients()` behaviour so all three categories in `get_all()` share the same collision contract.
- **FR-009b**: Before dedup, `get_npm_methods()` MUST validate each filter contribution has the six required top-level DTO keys (`category`, `slug`, `name`, `description`, `icon`, `meta`) AND that each value has the expected TYPE — the five string-typed keys hold `is_string()` values AND `meta` holds an `is_array()` value. Invalid entries (missing keys OR type-mismatched values) are silently dropped from the returned list and MUST trigger `_doing_it_wrong( 'ConnectionMethodRegistry::get_npm_methods', '<msg>', '<version>' )` under `WP_DEBUG` — matches F034's SEC-013-008 pattern and F021's `ConnectorProfileRegistry` validation stance so dev-time signals are consistent across all three categories. Type validation closes SEC-035-001 (a malicious filter contribution passing key-only validation with `slug => array()` or `meta => 'string'` cannot reach downstream consumers).
- **FR-010**: `get_clients()` MUST delegate to `AbstractMCPClient::get_all_registered_clients()` (F034) and map each returned instance to a DTO via the abstract's getter methods (`get_client_slug()`, `get_client_name()`, `get_description()`, `get_icon()`, `get_config_file()`, `get_top_level_key()`). It MUST NOT re-fire the `acrossai_mcp_client_classes` filter (that filter is fired inside `get_all_registered_clients()` — re-firing would double-invoke third-party callbacks).
- **FR-011**: `get_ai_connectors()` MUST delegate to `ConnectorProfileRegistry::instance()->get_profiles()` and map each returned `AbstractConnectorProfile` instance to a DTO via the profile's public methods (`get_slug()`, `get_name()`, `get_icon_url()`, `get_redirect_uri_whitelist()`). It MUST NOT re-fire the `acrossai_mcp_manager_connector_profiles` filter (same reason as FR-010).
- **FR-012**: `get_all()` MUST fire a new filter `acrossai_mcp_connection_methods` exactly once per call on the assembled three-category array (after `get_npm_methods()` + `get_clients()` + `get_ai_connectors()` are composed). Consumers may modify the entire result (remove categories, prepend/append DTOs, decorate `meta` fields). The filter MUST NOT be re-fired by the per-category getters.
- **FR-012a**: If the `acrossai_mcp_connection_methods` filter callback returns something invalid (not an array, or an array missing any of the three required category keys `npm` / `clients` / `ai_connectors`), `get_all()` MUST discard the filter's return value and use the pre-filter assembled result instead. The invalid return MUST trigger `_doing_it_wrong( 'ConnectionMethodRegistry::get_all', '<msg>', '<version>' )` under `WP_DEBUG`. Callers of `get_all()` are guaranteed a well-shaped three-category array regardless of consumer-code errors.
- **FR-013**: `find( string $category, string $slug ): ?array` MUST look up a single DTO by exact `(category, slug)` match against the `get_all()` output. Returns `null` for any unmatched category or slug. No fuzzy matching, no fallback categories.
- **FR-014**: The class MUST NOT modify `MCPClientsBlock`, `AIConnectorsTab`, `ConnectorProfileRegistry`, `AbstractConnectorProfile`, `AbstractMCPClient` (or any concrete client subclass), or any file under `admin/Partials/`. Only one existing file MAY be lightly touched: `NpmClientBlock` — see FR-015.
- **FR-015**: `NpmClientBlock` MUST expose a new public static helper `get_default_npm_method(): array` that returns the built-in NPM DTO (`command_template` + `enabled_option`). `ConnectionMethodRegistry::get_npm_methods()` reads the seed from this helper. `NpmClientBlock::render_body()` MAY be lightly refactored to consume the helper too so the template + option key has a single source of truth — but the rendered NPM tab output MUST remain byte-identical.
- **FR-016**: A new PHPUnit suite `discovery` MUST be registered in `phpunit.xml.dist` pointing at `tests/phpunit/Public/Discovery/`, with a matching step in `.github/workflows/phpunit.yml` (follow the F030 test-infrastructure precedent for adding a new suite). The suite uses `tests/bootstrap-wp.php` since the class reaches into the WP option storage and translation stack transitively.
- **FR-017**: The existing extension seams (`acrossai_mcp_client_classes`, `acrossai_mcp_manager_connector_profiles`) MUST be honoured transparently via delegation. F035 MUST NOT re-fire either filter inside `public/Discovery/`.

### WordPress Requirements

**PHP Version**: PHP 8.1+ (plugin minimum per AGENTS.md).
**WordPress Version**: 6.9+ (plugin minimum per AGENTS.md).
**Multisite**: Single-site (plugin is single-site per AGENTS.md; unchanged by this feature).
**Required Plugins / Packages**: None new.
**Optional Integrations**: Companion plugins may register `AbstractConnectorProfile` subclasses via `acrossai_mcp_manager_connector_profiles` and `AbstractMCPClient` subclasses via `acrossai_mcp_client_classes` — F035 surfaces those contributions in the discovery output via transparent delegation.

### Module Placement

**PHP Class(es)**:
- `public/Discovery/ConnectionMethodRegistry.php` (**new**) → namespace `AcrossAI_MCP_Manager\Public\Discovery` — the discovery API class. Follows the `public/` directory-layout convention established by `public/Renderers/` per DEC-CLIENT-RENDERER-PUBLIC-API.
- `public/Renderers/NpmClientBlock.php` (**light touch**) → add one new `public static function get_default_npm_method(): array` helper; optionally refactor `render_body()` to consume it (see FR-015). No other changes.

**Hook Registration**: F035 fires two new filters (`acrossai_mcp_npm_methods`, `acrossai_mcp_connection_methods`) from inside the discovery class's static methods. No `add_filter` calls are added or moved — the filters are DEFINED (fired) by this feature, not consumed by it. No `Main.php` wiring changes.

### Admin UI Requirements

No new admin UI. The discovery API is a programmatic surface for third-party plugins; end-user-facing rendering is done by the consumer (for example, the BuddyBoss add-on renders its own allowlist checkboxes and frontend UI).

### REST API Contract

No new REST routes. No changes to existing routes.

### Database / Storage

**No persistent storage**: N/A. Zero database schema impact, zero WP option reads or writes by F035 itself. `get_npm_methods()` reads the `enabled_option` NAME from the NPM DTO's `meta` field but does not read the option's VALUE — the consumer decides what to do with the gate flag.

### Security Checklist

*(Derived from Constitution §III — verify all that apply to this feature)*

- [x] All form/AJAX handlers verify nonce via `wp_verify_nonce()` or `check_ajax_referer()` — N/A (no forms).
- [x] All admin page renders check `current_user_can('manage_options')` (or more granular capability) — N/A (no admin pages).
- [x] All REST routes have explicit `permission_callback` — N/A (no REST routes).
- [x] All user input sanitized at system boundary with most-specific function — N/A (no user input; the two new filters accept trusted developer contributions at the same trust level as the existing extension seams).
- [x] All output escaped at point of rendering with most-specific function — N/A (F035 emits data structures, not rendered HTML; consumers own render-time escaping).
- [x] All DB queries use `$wpdb->prepare()` — N/A (no DB queries).
- [x] OAuth tokens / Application Passwords stored hashed (SHA-256 minimum) — N/A (no token handling).
- [x] File uploads (if any) validated for MIME type, extension, and size — N/A (no file uploads).

### Key Entities *(include if feature involves data)*

- **Connection Method** — a single connection option a site supports for an MCP client to talk to this WordPress site. Discriminated by `category` (`'npm'`, `'client'`, or `'ai_connector'`). Identified by `slug` (stable machine identifier, `[a-z0-9-]+` shape). Every connection method has the same six top-level DTO fields (see FR-006) with `meta` carrying category-specific extras.
- **NPM Method** — a `Connection Method` where `category === 'npm'`. Represents an npx / yarn / pnpm bridge command a user can copy-paste. The base plugin ships one built-in (the npx bridge); companion plugins can append via `acrossai_mcp_npm_methods`.
- **Client** — a `Connection Method` where `category === 'client'`. Represents an MCP-speaking desktop or CLI application (Claude Desktop, Cursor, Gemini CLI, etc.). Source of truth: `AbstractMCPClient::get_all_registered_clients()` (F034). F035 maps each returned instance to a DTO.
- **AI Connector** — a `Connection Method` where `category === 'ai_connector'`. Represents a hosted AI product's connector (Claude.ai, ChatGPT, etc.). Source of truth: `ConnectorProfileRegistry::get_profiles()` (F021). Base plugin ships zero; every AI connector is contributed by a companion plugin.

---

## Success Criteria *(mandatory)*

### Definition of Done Gates

All of the following MUST pass before this feature is considered complete:

- [ ] PHPCS validation: zero errors and zero warnings on all touched files.
- [ ] PHPStan level 8: zero errors on all touched files.
- [ ] ESLint: N/A (no JS added).
- [ ] PHPUnit tests written and passing for: default NPM DTO shape, `acrossai_mcp_npm_methods` filter contribution, NPM duplicate-slug later-wins dedup (FR-009a), NPM malformed DTO silently dropped + `_doing_it_wrong` under `WP_DEBUG` (FR-009b), `get_clients()` returns 8 DTOs post-F034 with populated metadata, `get_ai_connectors()` empty by default + non-empty with fake registered connector, `get_all()` composes 3 categories, `acrossai_mcp_connection_methods` filter can remove a category, malformed `acrossai_mcp_connection_methods` return falls back to pre-filter result + `_doing_it_wrong` under `WP_DEBUG` (FR-012a), `find()` returns matching DTO / `null` for miss, unified DTO shape stable across all three categories (helper assertion).
- [ ] New `discovery` PHPUnit suite registered in `phpunit.xml.dist` + CI step in `.github/workflows/phpunit.yml` — green on CI.
- [ ] Security checklist above: all applicable items verified (all N/A per this refactor's shape).
- [ ] All hooks wired in `Main.php` — N/A (no new WP hook registrations; F035 defines two new filters that are fired inside the class, not registered via `add_filter`).
- [ ] All new admin UI uses DataForm/DataViews — N/A (no new admin UI).
- [ ] No code duplication — F035 delegates to existing registries; does not re-implement any filter loop or validation logic.
- [ ] All functions, hooks, and classes prefixed with `acrossai_mcp_` — verified (two new filters both use the prefix; class lives in a namespace).
- [ ] `npm run validate-packages` passes.

### Measurable Outcomes

- **SC-001**: A third-party plugin author can call `\AcrossAI_MCP_Manager\Public\Discovery\ConnectionMethodRegistry::instance()->get_all()` and receive a JSON-serializable array with exactly three category keys, each containing DTOs of the specified shape.
- **SC-002**: Every DTO across all three categories has the same six top-level keys (`category`, `slug`, `name`, `description`, `icon`, `meta`). Verified by a data-provider parameterized test that iterates the entire `get_all()` output and asserts key presence on each DTO.
- **SC-003**: The new filter `acrossai_mcp_npm_methods` fires when `get_npm_methods()` is called; test with a registered fake contribution asserts it appears in the returned list.
- **SC-004**: The new filter `acrossai_mcp_connection_methods` fires exactly ONCE when `get_all()` is called; test with a filter that removes a category asserts the removal is reflected. Test that the filter does NOT fire on per-category getters (`get_npm_methods()`, `get_clients()`, `get_ai_connectors()`) called in isolation.
- **SC-005**: `grep -rn "apply_filters.*acrossai_mcp_client_classes\|apply_filters.*acrossai_mcp_manager_connector_profiles" public/Discovery/` returns zero hits — delegation, not re-firing (FR-017 enforcement).
- **SC-006**: `grep -rn "ConnectionMethodRegistry" includes/` returns zero hits — `public/` layer must never be imported into `includes/` (one-way dependency).
- **SC-007**: Rendered HTML of the NPM tab (server-edit → NPM) is byte-identical before and after F035. Verified by manual DOM diff pre/post; automated regression is a hand-authored key-marker test if desired.
- **SC-008**: Both new filters fail identically per SEC-013-008. Verified by two PHPUnit tests — one for `acrossai_mcp_npm_methods` (registers a callback that returns malformed DTOs including both missing-key AND type-drift variants — `slug => array()`, `meta => 'string'`; asserts every invalid entry dropped + `_doing_it_wrong` fires under `WP_DEBUG`) and one for `acrossai_mcp_connection_methods` (registers a callback that returns garbage; asserts `get_all()` returns the pre-filter three-category array + `_doing_it_wrong` fires under `WP_DEBUG`).

---

## Assumptions

- The BuddyBoss add-on (the motivating consumer) is out of scope for this feature. F035 delivers the discovery API; the add-on is a separate project in a separate repository that will consume this API.
- Third-party contributions to the two new filters (`acrossai_mcp_npm_methods`, `acrossai_mcp_connection_methods`) are trusted at the "installed by admin" level — the same trust anchor as the existing `acrossai_mcp_client_classes` and `acrossai_mcp_manager_connector_profiles` extension seams.
- F034 has shipped (0.1.8, `AbstractMCPClient::get_all_registered_clients()` + six metadata getter methods on the abstract). F035 delegates to F034; opening F035 development against `main` requires F034 to be present on `main`. Confirmed by the release chain that shipped 0.1.8 on 2026-07-26.
- The `NpmClientBlock::get_default_npm_method(): array` helper introduction (FR-015) is a light-touch refactor to establish a single source of truth for the NPM template + option key. Not a scope violation of FR-014's "do not touch NpmClientBlock" — the exception is explicitly carved out in FR-015 and bounded to one new static helper plus an optional `render_body()` refactor that keeps rendered output byte-identical (SC-007).
- DTO shape freezes at plugin 1.0.0 per `@experimental` policy (`DEC-CLIENT-RENDERER-PUBLIC-API`). Third-party plugins targeting pre-1.0.0 versions MUST tolerate shape churn between releases; post-1.0.0, breaking DTO changes require a MAJOR version bump per the plugin's semver policy.
- The new `discovery` PHPUnit suite uses `tests/bootstrap-wp.php` (WP bootstrap) rather than pure-PHP `tests/bootstrap.php` because F035 delegates transitively into `ConnectorProfileRegistry` (which uses `_doing_it_wrong` and abilities-API touch points) and `NpmClientBlock` (which reads `home_url()` and `get_option()`). Adding WP function stubs to `tests/bootstrap.php` for these transitive dependencies would grow the stub set past the ~10-symbol ceiling documented in A18 — the "refactor-back-to-pure OR move-to-bootstrap-wp" signal — so bootstrap-wp is the correct choice here.
