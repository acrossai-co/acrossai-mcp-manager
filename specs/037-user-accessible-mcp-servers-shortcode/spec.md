# Feature Specification: User-Accessible MCP Servers Shortcode + Reusable Base Class

**Feature Branch**: `037-user-accessible-mcp-servers-shortcode`
**Created**: 2026-07-29
**Status**: Draft
**Input**: User description: Add a new frontend shortcode `[acrossai_mcp_servers]` that, for the current logged-in user, enumerates every MCP server they can access (via the F015 access-control wrapper) whose F037 Embeds tab has (a) the master toggle ON, and (b) at least one enabled connection method — and renders those enabled DTOs per server. Ships with a data-only abstract base class `AbstractUserServersRenderer` under `public/Renderers/UserServers/` so companion plugins (planned BuddyBoss add-on, potential WooCommerce My Account extension, WPUM, MemberPress) can consume the same enumeration primitive without re-implementing the gate cascade. The base has zero rendering; a concrete `UserServersBlock` singleton owns the shortcode registration + default HTML + inline scoped `<style>`. Feature is pure composition on top of F011 + F015 + F035 + F037 — no new DB tables, no new REST endpoints, no new admin surface, no schema drift. Two new filters extend the composition: `acrossai_mcp_user_accessible_servers` (reshape the payload per context) + `acrossai_mcp_servers_shortcode_html` (override markup without subclassing). See `docs/planings-tasks/038-user-accessible-mcp-servers-shortcode.md` for the full engineering brief (brief numbered 038 because the F037 brief established a brief-vs-spec-dir offset of one; the spec dir is `037-` per next-sequential numbering).

## Clarifications

### Session 2026-07-29

- Q: Which transport categories should the new shortcode enumerate per server? → A: **All three** (NPM + MCP Clients + AI Connectors) — matches the F037 Embeds tab UI. Companion plugins that register a fourth transport via `acrossai_mcp_embed_transports` automatically surface too (filter-driven — F038 never re-fires the filter, only iterates the already-enumerated instances).
- Q: What should the shortcode tag be? → A: **`[acrossai_mcp_servers]`** — distinct from F037's per-server `[acrossai_mcp_embed]` (which takes explicit `server=` + `category=` attributes). The new one takes no required attributes; it discovers what to render.
- Q: How should the base class be positioned for third-party reuse (BuddyPress, WooCommerce)? → A: **Data-only base + concrete shortcode child.** The abstract base `AbstractUserServersRenderer` exposes exactly one public method `get_accessible_servers( ?int $user_id = null ): array` returning the structured data. A concrete `UserServersBlock` singleton (extends the base) adds the shortcode registration + default HTML + inline styling. Third parties subclass the base and render however they want. No rendering method on the base — companions never inherit HTML they'd have to override.
- Q: Anonymous users (logged out) — what should the shortcode render? → A: **Silent no-render (empty string).** Matches `EmbedBlockRenderer` conventions in F037. Access control is a logged-in-user concept; anonymous state is not "access denied", it's "no user to evaluate", so we return nothing rather than an empty-state message.
- Q: When a logged-in user has access to zero servers with embeds enabled, what should the shortcode render? → A: **Wrapper `<div>` with a translatable empty message** (attribute-overridable via `empty_message="…"`). Themes can style the empty state. This differs from the anonymous case because we DID evaluate access — the answer is "you have access to nothing yet" which is a real user-facing state, not a "no user" state.
- Q: Where should styling live? → A: **Inline `<style>` in the shortcode output, emitted once per request** via a private static flag. Class-prefix everything with `acrossai-mcp-servers` to avoid leaking generic selectors. No `wp_enqueue_scripts` (would fire on pages without the shortcode). No external CSS file. No JS. Concrete `UserServersBlock` owns the styling — the base is data-only.

## User Scenarios & Testing *(mandatory)*

### User Story 1 — Logged-in user sees only the MCP servers they can reach (Priority: P1)

A logged-in site member visits their profile page (or any page containing the `[acrossai_mcp_servers]` shortcode). The shortcode lists every MCP server the operator has granted the member access to (via each server's F015 Access Control tab) that also has the F037 Embeds tab master toggle enabled. For each server, the shortcode shows only the connection methods the operator toggled ON in the Embeds tab — nothing else. If the operator later restricts the member's access to a server, that server disappears from the shortcode's output on the next page load. If the operator disables the master embeds toggle for a server, it disappears too. Zero information leaks to the wrong user.

**Why this priority**: This is the whole reason the feature exists — a first-party frontend surface for the per-user, per-server access model F015 + F037 establish. Without it, operators have no built-in way to expose the model to end-users, and the BuddyBoss add-on has to re-implement the gate cascade to build its profile widget.

**Independent Test**: Log in as an admin. Add server "team-support" with Embeds → master ON, Claude Desktop + NPM enabled. Add server "internal-dev" with Embeds → master OFF. Add server "billing" with Access Control → only `administrator` role. Create a page containing only `[acrossai_mcp_servers]`. View as admin → three servers visible with correct DTOs. View as subscriber → only "team-support" visible (no admin-only "billing", no disabled "internal-dev"). Log out → nothing renders.

**Acceptance Scenarios**:

1. **Given** a logged-in user with access to server "A" (Embeds master ON, Claude Desktop enabled) and no access to server "B" (Embeds master ON, VSCode enabled), **When** the shortcode `[acrossai_mcp_servers]` renders on a page they view, **Then** the output lists server "A" with a Claude Desktop entry, and does NOT mention server "B" anywhere.
2. **Given** the same user + a server "C" that they DO have access to but whose Embeds master toggle is OFF, **When** the shortcode renders, **Then** server "C" does NOT appear (F037 master gate).
3. **Given** the same user + a server "D" that they have access to and whose master toggle is ON but where every per-DTO toggle in `_embeds_clients` is OFF, **When** the shortcode renders, **Then** server "D" does NOT appear (no DTOs → no useful content → drop the server entirely).
4. **Given** an anonymous visitor (logged out), **When** the shortcode renders on a public page, **Then** nothing renders (empty string, no wrapper).
5. **Given** a logged-in user with access to zero embed-enabled servers, **When** the shortcode renders, **Then** the wrapper `<div class="acrossai-mcp-servers acrossai-mcp-servers--empty">` renders containing the default empty message (or the value of the `empty_message` attribute if supplied).

---

### User Story 2 — Companion plugin reuses the enumeration primitive without touching HTML (Priority: P1)

A BuddyBoss add-on developer needs to add a "My MCP Servers" tab to member profiles that lists the same per-user server view but wrapped in BuddyBoss's own template + template tags. They subclass `AbstractUserServersRenderer` in their add-on plugin, call `$this->get_accessible_servers( bp_displayed_user_id() )` inside the tab's render callback, and iterate the returned array to build BuddyBoss-native markup. They do NOT call `add_shortcode` (BuddyBoss template tags handle output). They do NOT need to know how F015 access control, F037 embeds toggles, or F035 DTOs compose — the base class encapsulates the entire gate cascade.

**Why this priority**: This is the extensibility contract that makes F038 a durable primitive rather than a one-shot shortcode. Symmetric with F034 (`AbstractMCPClient` for MCP clients) and F035 (`ConnectionMethodRegistry` for DTOs) — F038 completes the trio with the "user-scoped enumeration" primitive. Not blocking core F038 shipping (first-party shortcode works standalone), but required for every companion-plugin motivating consumer (BuddyBoss add-on, WooCommerce My Account extension, WPUM, MemberPress).

**Independent Test**: Register an mu-plugin with a class extending `AbstractUserServersRenderer`. Call `get_accessible_servers( $user_id )` for a known user. Assert the returned array has the documented shape (list of `[ server_id, server_slug, server_name, description, transports[] ]` entries with per-transport `dtos[]` filtered to only enabled DTOs). Change a server's F015 Access Control rule to deny that user → assert the server drops from the return. Change the server's Embeds master toggle → assert the server drops. Change one DTO's toggle in the Embeds tab → assert only that DTO drops from the server's `dtos[]` list, other DTOs remain.

**Acceptance Scenarios**:

1. **Given** a companion plugin subclass of `AbstractUserServersRenderer` with a stub `render_bp_tab()` method that iterates `$this->get_accessible_servers( $user_id )` and echoes the server names, **When** the tab renders for a user with access to two servers, **Then** both server names appear in that specific plugin's own markup (not F038's).
2. **Given** the same companion plugin, **When** the F038 shortcode is placed on a different page for the same user, **Then** the F038 shortcode's own default HTML renders — companion plugin's presence does not interfere with the first-party output.
3. **Given** a companion plugin uses `get_accessible_servers( $some_other_user_id )` (server-rendered per-profile scenario), **When** the calling user has `manage_options` but the target user does not have access to server "A", **Then** the return does NOT include server "A" — the gate evaluates for the target user, not the calling user.

---

### User Story 3 — Filter-based customization without subclassing (Priority: P2)

A site admin has installed both F038 (first-party plugin) and a third-party plugin that wants to (a) exclude servers whose slug matches a "prefix-X-" pattern from the shortcode output on certain pages, and (b) wrap the shortcode output in a container div with the site's custom CSS class. The admin does not want to write a subclass. They register a hook on `acrossai_mcp_user_accessible_servers` to filter the data payload, and a hook on `acrossai_mcp_servers_shortcode_html` to wrap the final HTML. F038 respects both filters; no plugin-code changes required.

**Why this priority**: Filter-based extensibility is table-stakes for a public WordPress renderer. Enables lightweight customization without the maintenance overhead of subclassing. Symmetric with F037's `acrossai_mcp_embed_render_html` filter and F034's `acrossai_mcp_client_classes` — same idiom.

**Independent Test**: Register two filter hooks in an mu-plugin: one on `acrossai_mcp_user_accessible_servers` that unset entries where `strpos( $server['server_slug'], 'prefix-x-' ) === 0`, another on `acrossai_mcp_servers_shortcode_html` that wraps the output in `<div class="my-wrapper">`. Load a page containing the shortcode. Assert filtered-out servers do not appear AND the wrapper div surrounds the result.

**Acceptance Scenarios**:

1. **Given** a filter hook on `acrossai_mcp_user_accessible_servers` that removes one entry from the array, **When** the shortcode renders, **Then** the removed server does not appear in the output.
2. **Given** a filter hook on `acrossai_mcp_servers_shortcode_html` that prepends an HTML comment, **When** the shortcode renders, **Then** the comment appears in the returned string.
3. **Given** no filter hooks are registered, **When** the shortcode renders, **Then** the default HTML shape (single `<div class="acrossai-mcp-servers">` with `<ul class="acrossai-mcp-servers__list">` etc.) is emitted.

---

### Edge Cases

- **wpb-access-control package absent**: `AcrossAI_MCP_Access_Control::user_has_server_access` fails open per F015 — every server with the embeds master toggle ON becomes visible. F038 inherits this; the shortcode renders enumerated servers gated only by the embeds toggle. Documented, expected.
- **Server row deleted between page loads**: F037 already prunes the `_embeds_enabled` + `_embeds_clients` meta rows on server delete (via `Main::cleanup_embed_transports_on_server_delete`). The next `get_accessible_servers()` call sees the server missing from `MCPServerQuery`; it silently drops from output.
- **Server exists + master ON + `_embeds_clients` malformed JSON**: `AbstractEmbedTransport::is_enabled_for_server` treats a JSON decode failure as "empty enabled set" and returns `false` per DTO. Server appears with zero DTOs → dropped from output by the "no DTOs → drop server" rule. No error, no leak.
- **F035 DTO with missing `slug` field**: F038 iterates `$transport->get_dtos()` and skips entries where `is_string( $dto['slug'] ?? null )` is false. Malformed DTOs silently drop, matching F037 shortcode behavior.
- **F035 DTO with an icon that looks like a URL but is not http/https**: The base URL check is `is_string() && starts_with('http:' or 'https:')`. A non-URL like `data:image/…` renders as `esc_html` text instead of an `<img>`, matching `EmbedBlockRenderer`'s discipline. Safe fallback.
- **Same request renders the shortcode twice**: The inline `<style>` block emits only on the first call per request (private static flag `$style_emitted`). Second render has no `<style>` block — the browser already applied it.
- **Companion plugin registers a fourth `AbstractEmbedTransport` subclass**: `get_all_registered_transports()` picks it up automatically; F038 iterates it identically to the built-in three. Zero F038 code changes required.
- **Third-party filter on `acrossai_mcp_user_accessible_servers` returns a non-array**: Silent recovery — the shortcode treats it as `[]` and renders the empty-message wrapper. Matches WordPress's tolerant filter conventions.

---

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The plugin MUST expose a public method `AbstractUserServersRenderer::get_accessible_servers( ?int $user_id = null ): array` that returns a structured list of MCP servers the specified user can access whose Embeds master toggle is ON and which have at least one enabled DTO across any registered transport.
- **FR-002**: When `$user_id` is `null` or `0` or negative, the method MUST return an empty array (anonymous / invalid input short-circuit).
- **FR-003**: The method MUST use `AcrossAI_MCP_Access_Control::instance()->user_has_server_access( $user_id, $server_id )` (F015 / F032) as its per-server access gate — no direct queries against the `wp_mcp_access_control` table or the wpb-access-control provider list.
- **FR-004**: The method MUST use `AbstractEmbedTransport::is_enabled_for_server( $server_id, $transport_key, $dto_slug )` (F037) as its per-DTO gate — no direct reads of `_embeds_enabled` or `_embeds_clients` meta.
- **FR-005**: The method MUST enumerate transports via `AbstractEmbedTransport::get_all_registered_transports()` (F037) — no `apply_filters( 'acrossai_mcp_embed_transports', … )` inside F038's own code (delegation, not re-firing).
- **FR-006**: The method MUST enumerate MCP servers via `MCPServerQuery::instance()->query( [ 'is_enabled' => 1, 'number' => -1 ] )` (F011) — no direct `$wpdb` queries.
- **FR-007**: The method MUST fire `apply_filters( 'acrossai_mcp_user_accessible_servers', $data, $user_id )` exactly once per invocation, on the assembled data, immediately before return.
- **FR-008**: When the method returns servers, entries MUST be ordered alphabetically by `server_name` (case-insensitive). Transports within a server MUST preserve the priority-ASC order established by `get_all_registered_transports()`.
- **FR-009**: The plugin MUST register a shortcode `[acrossai_mcp_servers]` bound to `UserServersBlock::render_shortcode()`. The shortcode registration itself MUST live in `UserServersBlock::register_shortcode()`; the `add_shortcode()` call is wired via `Main::define_public_hooks()` on the `init` action (per A1).
- **FR-010**: The shortcode MUST return an empty string when the current user is anonymous (`get_current_user_id() === 0`).
- **FR-011**: The shortcode MUST accept three optional attributes: `heading` (default `''`), `show_description` (default `'1'`), `empty_message` (default translatable string via `__()`).
- **FR-012**: When `get_accessible_servers()` returns an empty array for a logged-in user, the shortcode MUST render `<div class="acrossai-mcp-servers acrossai-mcp-servers--empty">` containing the `empty_message` value (wrapped in `<p>`).
- **FR-013**: When `get_accessible_servers()` returns servers, the shortcode MUST render the DOM shape documented in the planning brief (single outer `<div class="acrossai-mcp-servers">`, `<ul class="acrossai-mcp-servers__list">`, per-server `<li>` with `data-server-id` + `data-server-slug` attributes, per-transport `<section>` with `data-key`, per-DTO `<li>` with `data-slug`).
- **FR-014**: All user-supplied strings emitted into HTML MUST be escaped at the render boundary — `esc_html` for text content, `esc_attr` for attribute values, `esc_url` for URLs. Applies to server names, descriptions, DTO names, DTO icons (when URL), slugs.
- **FR-015**: DTO icons that look like URLs (`is_string` + starts with `http:` or `https:`) MUST render as `<img src="…" alt="">` via `esc_url`; other icon values MUST render as `esc_html` text.
- **FR-016**: The shortcode MUST emit a scoped inline `<style>` block on its first invocation per request (tracked via a private static flag) and omit it on subsequent invocations in the same request. All CSS selectors MUST be prefixed with `acrossai-mcp-servers` — no generic selectors, no theme opinions.
- **FR-017**: The shortcode MUST fire `apply_filters( 'acrossai_mcp_servers_shortcode_html', $html, $data, $atts )` exactly once per invocation, on the final HTML, immediately before return.
- **FR-018**: F038 MUST NOT enqueue any external CSS or JS file. No `wp_enqueue_style`, no `wp_enqueue_script`, no `wp_enqueue_scripts` hook.
- **FR-019**: F038 MUST NOT register any REST endpoints, admin screens, options, or database tables. Feature is pure composition on shipped infrastructure.
- **FR-020**: The abstract base `AbstractUserServersRenderer` MUST have no rendering method, no shortcode registration, no hook registration. Companion plugins subclass without inheriting any HTML they would need to override.
- **FR-021**: The concrete `UserServersBlock` MUST implement the singleton pattern per A2 (private constructor, static `instance()` method returning a shared `?self` instance).
- **FR-022**: Both new classes MUST be documented as `@experimental May change without notice before 1.0.0` per `DEC-CLIENT-RENDERER-PUBLIC-API`.
- **FR-023**: The plugin MUST NOT re-fire any filter owned by an upstream registry. Specifically:
  - **FR-023a** no `apply_filters( 'acrossai_mcp_embed_transports', … )` anywhere inside `public/Renderers/UserServers/` (owned by `AbstractEmbedTransport::get_all_registered_transports()`).
  - **FR-023b** no `apply_filters( 'acrossai_mcp_client_classes', … )` anywhere inside `public/Renderers/UserServers/` (owned by `AbstractMCPClient::get_all_registered_clients()`).
  - **FR-023c** no `apply_filters( 'acrossai_mcp_manager_connector_profiles', … )` anywhere inside `public/Renderers/UserServers/` (owned by `ConnectorProfileRegistry::get_profiles()`).

  Each sub-clause is independently grep-verifiable. Testing is bundled under the single T023 task in `tasks.md` (three grep commands executed in sequence). Sub-clause split preserved for finer test traceability without renumbering downstream FR-024..FR-028.
- **FR-024**: F038 code MUST NOT read `_embeds_enabled` or `_embeds_clients` meta keys directly. All reads go through `AbstractEmbedTransport::is_enabled_for_server` to preserve the R2 per-request memoization + fail-open contract.
- **FR-025**: The `AcrossAI_MCP_Manager\Public\Renderers\UserServers` namespace MUST NOT be imported anywhere under `includes/` — the `public/` → `includes/` dependency direction is one-way. Grep-gate enforced.
- **FR-026**: A new PHPUnit test suite named `user-servers` MUST be registered in `phpunit.xml.dist` pointing at `tests/phpunit/Public/Renderers/UserServers/`, with a matching CI job in `.github/workflows/phpunit.yml` invoking it via `tests/bootstrap-wp.php`.
- **FR-027**: The test suite MUST cover: anonymous short-circuit, no-servers-in-DB, master-toggle-off drops server, zero-DTOs drops server, DTOs-enabled-but-F015-denies drops server, F015-fail-open path (package absent), filter round-trip on `acrossai_mcp_user_accessible_servers`, render smoke, empty-state render, anonymous render, filter round-trip on `acrossai_mcp_servers_shortcode_html`, `<style>` block emitted-once-per-request.
- **FR-028**: The test suite MUST include a third-party-extensibility test that (a) subclasses `AbstractUserServersRenderer` and consumes `get_accessible_servers()` as data-only, (b) uses `acrossai_mcp_servers_shortcode_html` to wrap output, and (c) registers a fake fourth transport via `acrossai_mcp_embed_transports` and asserts its DTOs surface in the F038 payload with zero F038 code changes.

### WordPress Requirements

**PHP Version**: PHP 8.0+ (plugin supports 7.4 minimum; constitution target is 8.0)
**WordPress Version**: 6.9+
**Multisite**: Single-site only — matches plugin-wide policy per AGENTS.md.
**Required Plugins / Packages**: N/A (no new dependencies — F015 + F035 + F037 shipped in 0.1.8; F038 is additive on the same tree).
**Optional Integrations**: `wpb-access-control` (v2) — must degrade gracefully if absent per F015's fail-open contract, which F038 inherits transitively.

### Module Placement

**PHP Class(es)**:
- `public/Renderers/UserServers/AbstractUserServersRenderer.php` → namespace `AcrossAI_MCP_Manager\Public\Renderers\UserServers` — data-only, `abstract`, `@experimental`. Consumed by companion plugins directly.
- `public/Renderers/UserServers/UserServersBlock.php` → namespace `AcrossAI_MCP_Manager\Public\Renderers\UserServers` — concrete, `final`, singleton per A2, extends the abstract base. Owns the `[acrossai_mcp_servers]` shortcode + default HTML + inline `<style>`.

**Hook Registration**: All hook wiring for F038 lives in `includes/Main.php::define_public_hooks()` — one `add_action( 'init', … )` insertion binding `UserServersBlock::register_shortcode`. No hook registration in either new class's constructor.

### Admin UI Requirements

**No admin UI**: F038 adds no admin screens, no settings pages, no meta boxes. F037's Embeds tab is the sole admin surface for the toggles F038 reads.

### REST API Contract

**No REST surface**: F038 adds zero routes. Consumes existing infrastructure only.

### Database / Storage

**No persistent storage**: F038 adds zero tables, zero options, zero meta keys. Reads flow through:
- `MCPServerQuery::instance()->query()` (F011 — `wp_acrossai_mcp_servers`)
- `AbstractEmbedTransport::is_enabled_for_server()` (F037 — `wp_acrossai_mcp_servers_meta` via memoized reader)
- `AcrossAI_MCP_Access_Control::user_has_server_access()` (F015 — `wp_mcp_access_control` via wpb-access-control vendor manager)

### Security Checklist

*(Derived from Constitution §III — verify all that apply to this feature)*

- [ ] N/A — no form/AJAX handlers (F038 has no input surface).
- [ ] N/A — no admin page renders.
- [ ] N/A — no REST routes.
- [ ] N/A — no user input crosses a boundary (shortcode attributes are the only inputs; all pass through `shortcode_atts()` + boundary escape).
- [x] All output escaped at point of rendering with most-specific function (`esc_html()`, `esc_attr()`, `esc_url()`) — FR-014, FR-015.
- [ ] N/A — no DB queries beyond delegated calls (which each honor `$wpdb->prepare()` internally).
- [ ] N/A — no OAuth / password handling.
- [ ] N/A — no file uploads.
- [x] **Additional gate**: F038 MUST NOT bypass the F015 access-control gate (FR-003). Grep-gate enforced (`grep for user_has_server_access` in F038 code).
- [x] **Additional gate**: F038 MUST NOT bypass the F037 per-DTO gate (FR-004). Grep-gate enforced (`grep for is_enabled_for_server` in F038 code).
- [x] **Additional gate**: F038 MUST NOT read `_embeds_enabled` or `_embeds_clients` meta keys directly (FR-024). Grep-gate enforced.

### Key Entities

F038 introduces no new entities. It projects existing entities into a new shape:

- **AccessibleServer**: A per-request, in-memory projection of an `MCPServer` row filtered by (a) F015 access-control verdict for the current user, (b) F037 embeds master toggle, (c) at least one F035 DTO passing the F037 per-DTO gate. Attributes: `server_id` (int), `server_slug` (string), `server_name` (string), `description` (string), `transports` (list of `AccessibleTransport`).
- **AccessibleTransport**: A per-request, in-memory projection of an `AbstractEmbedTransport` filtered to only the DTOs that pass the per-DTO gate for the current `(server_id, transport_key, dto_slug)` triple. Attributes: `key` (string — `client` / `npm` / `ai_connector` / third-party), `label` (translated string from `get_checkbox_label()`), `priority` (int), `dtos` (list of F035 DTOs — `[ slug, name, icon, description, meta ]`).

---

## Success Criteria *(mandatory)*

### Definition of Done Gates

All of the following MUST pass before this feature is considered complete:

- [ ] PHPCS validation: zero errors and zero warnings (`vendor/bin/phpcs`)
- [ ] PHPStan level 8: zero errors (`vendor/bin/phpstan`)
- [ ] ESLint: N/A (no JS shipped).
- [ ] PHPUnit tests written and passing for all new PHP logic — new `user-servers` suite green (FR-026).
- [ ] Security checklist above: all applicable items verified.
- [ ] All hooks wired in `Main.php` — none in class constructors (FR-009).
- [ ] No new admin UI (feature has none).
- [ ] No code duplication — every gate call delegates to a shipped upstream helper (FR-003 through FR-006).
- [ ] All functions, hooks, and classes prefixed with `acrossai_mcp_`.
- [ ] `npm run validate-packages` passes.
- [ ] Grep-gate: `grep -rn "apply_filters.*acrossai_mcp_embed_transports" public/Renderers/UserServers/` returns zero hits.
- [ ] Grep-gate: `grep -rn "apply_filters.*acrossai_mcp_client_classes" public/Renderers/UserServers/` returns zero hits.
- [ ] Grep-gate: `grep -rn "_embeds_enabled\|_embeds_clients" public/Renderers/UserServers/` returns zero hits.
- [ ] Grep-gate: `grep -rn "UserServers" includes/` returns zero hits (one-way dependency).
- [ ] Grep-gate: `grep -rn "add_shortcode" public/Renderers/UserServers/` returns exactly one hit (inside `UserServersBlock::register_shortcode`).

### Measurable Outcomes

- **SC-001**: A logged-in user with access to N ≥ 1 embed-enabled servers sees exactly those N servers in the shortcode output — no more, no less (FR-001, FR-013). Verified by PHPUnit + manual end-to-end test.
- **SC-002**: A logged-in user with access to zero embed-enabled servers sees the `--empty` wrapper with the default (or overridden) empty message (FR-012). Verified by PHPUnit + manual.
- **SC-003**: An anonymous visitor sees zero HTML output from the shortcode — no wrapper, no message (FR-010). Verified by PHPUnit + manual (log-out browser session).
- **SC-004**: A companion plugin subclass of `AbstractUserServersRenderer` calling `get_accessible_servers()` receives the same data payload as the first-party shortcode uses to render — proving the base is a durable reuse primitive (User Story 2, FR-020). Verified by PHPUnit third-party-extensibility test (FR-028).
- **SC-005**: A companion plugin registering a fourth transport via `acrossai_mcp_embed_transports` sees its enabled DTOs surface in the F038 payload with zero F038 code changes — proving the composition is truly filter-driven (User Story 2, FR-005). Verified by PHPUnit (FR-028).
- **SC-006**: Every gate change (revoke F015 access, disable master toggle, disable a per-DTO toggle) is reflected in the shortcode's next render — no stale caches, no memoization beyond the R2 per-request one already in F037 (FR-004, edge cases). Verified by PHPUnit + manual.
- **SC-007**: The shortcode's inline `<style>` block appears exactly once in the HTML output regardless of how many times the shortcode is placed on a page (FR-016). Verified by PHPUnit (regex count).
- **SC-008**: All required grep-gates in the Definition of Done above return zero hits — verified as part of the CI job.
- **SC-009**: Full existing PHPUnit suite remains green post-F038 (no regressions in `discovery`, `embeds`, `mcpclients`, `abilities`, `oauth`, `renderers`, `database`, `mcp`).

---

## Assumptions

- F015 (Access Control v2), F035 (`ConnectionMethodRegistry`), and F037 (`AbstractEmbedTransport` + Embeds tab) all shipped in 0.1.8 and are present on `main` when F038 development starts. F038 does not modify them.
- WordPress ≥ 6.9 provides `get_current_user_id()`, `add_shortcode()`, `shortcode_atts()`, `esc_html()`, `esc_attr()`, `esc_url()`, `apply_filters()` at their documented behaviors. No version-specific workarounds required.
- The wpb-access-control vendor package may or may not be active; F038 inherits F015's fail-open contract transitively — if the package is absent, `user_has_server_access` returns `true` for every `(user, server)` pair and the shortcode gates only on the F037 embeds toggle.
- Companion plugins (BuddyBoss add-on, WooCommerce My Account extension, WPUM, MemberPress) ship in separate repositories. F038's job is to expose `AbstractUserServersRenderer` + the two filters; the add-ons subclass or filter. No companion-plugin integration is bundled with F038.
- Multisite is out of scope (matches plugin-wide policy). Behavior on a multisite install with per-site MCP servers is documented as "single-site scope only" — no `switch_to_blog()` cascade.
- The `@wordpress/dataviews` / `@wordpress/dataforms` UI contract from AGENTS.md does not apply — F038 ships zero admin JS and zero admin HTML.
- The `[acrossai_mcp_servers]` shortcode tag is available (not registered by any other loaded plugin). Documented; a hypothetical name collision is a caller-configuration issue, not an F038 defect.
- Ordering by `server_name` (case-insensitive) is a reasonable default. Third parties needing custom ordering hook `acrossai_mcp_user_accessible_servers` and re-sort the array; F038 does not ship its own ordering attribute.
- Frontend interactivity (per-server "copy config" button, expand/collapse) is out of scope. Base MVP is data + inline HTML + scoped `<style>` — no JS. A companion plugin or a follow-up feature owns interactivity if the market demand emerges.
- Block-editor block that wraps the shortcode's output is out of scope — parallel of F037's block-editor deferral. Same data source, same filters; a follow-up feature or a companion plugin can add the block wrapper.
