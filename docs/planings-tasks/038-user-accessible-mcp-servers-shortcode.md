# Planning: User-Accessible MCP Servers Shortcode + reusable base class (Feature 038)

**Depends on**: F015 (Access Control v2) + F035 (`ConnectionMethodRegistry`) + F037 (`AbstractEmbedTransport` + Embeds tab) all shipped in 0.1.8. Opening F038 development against `main` requires the F037 branch to be present on `main`.

Add a new frontend shortcode `[acrossai_mcp_servers]` that, for the current logged-in user, enumerates every MCP server they can access (via the F015 access-control wrapper) whose F037 Embeds tab has (a) the master toggle ON, and (b) at least one enabled connection method — and renders those enabled DTOs per server. Ships with a **data-only abstract base class** `AbstractUserServersRenderer` under `public/Renderers/UserServers/` so companion plugins (planned BuddyBoss add-on, potential WooCommerce My Account extension) can consume the same enumeration primitive without re-implementing the gate cascade. The base has zero rendering; a concrete `UserServersBlock` singleton owns the shortcode registration + default HTML + inline scoped `<style>` (styling ships inline with the shortcode per Q1 clarification below).

The feature is **pure composition** on top of already-shipped infrastructure. No new DB tables, no new REST endpoints, no new admin surface, no schema drift. It reuses:

- `MCPServerQuery::instance()->query()` (F011) to enumerate enabled servers.
- `AcrossAI_MCP_Access_Control::instance()->user_has_server_access( $user_id, $server_id )` (F015 / F032) as the per-server access gate — fail-open when the wpb-access-control package is missing, v2 vendor manager handles admin bypass internally.
- `AbstractEmbedTransport::get_all_registered_transports()` (F037) as the transport enumeration source — same filter (`acrossai_mcp_embed_transports`) already open to third-party companion plugins.
- `AbstractEmbedTransport::is_enabled_for_server( $server_id, $transport_key, $dto_slug )` (F037) — two-check gate (master `_embeds_enabled` toggle + per-DTO presence in `_embeds_clients` JSON) with R2 per-request memoization.
- F035 DTOs (category / slug / name / icon / description / meta) surfaced through each transport's `get_dtos()`.

Two new filters extend the composition:

- `acrossai_mcp_user_accessible_servers` — fired once inside `AbstractUserServersRenderer::get_accessible_servers()` on the assembled data before return; consumers reshape the payload per context (e.g. BuddyPress limits to servers whose slug matches a profile-field allowlist).
- `acrossai_mcp_servers_shortcode_html` — fired inside `UserServersBlock::render_shortcode()` on the final HTML; consumers override markup without subclassing.

---

## Motivating Consumer

The planned BuddyBoss add-on (also motivating F035 + F037) needs a **user-scoped index widget** on a member's profile: "here are the MCP servers you're allowed to reach, and here's how to connect each one." F037 already gives operators the toggles; F035 already gives BuddyBoss the DTOs; what's missing is the **user-filtered enumeration** that composes both. F038 supplies that primitive as a base class the add-on subclasses, plus a first-party shortcode `[acrossai_mcp_servers]` that ships with the plugin so operators can drop it on any page (My Account, Dashboard widget, sidebar, custom profile template) without waiting for the add-on.

WooCommerce / WPUM / MemberPress integrations follow the same shape: subclass `AbstractUserServersRenderer` in a small companion plugin, call `get_accessible_servers( get_current_user_id() )` inside the plugin's own template hook, render however that ecosystem prefers.

---

## Clarifications (Q&A captured from the user prompt)

- **Q1 — Which transports?** All three (NPM + MCP Clients + AI Connectors). Matches the F037 Embeds tab UI.
- **Q2 — Shortcode tag?** `[acrossai_mcp_servers]`.
- **Q3 — Base class shape?** Data-only base (no rendering) + concrete shortcode child that owns default HTML + inline styling.
- **Q4 — Anonymous users?** Silent no-render (matches `EmbedBlockRenderer` conventions in F037).
- **Q5 — No servers accessible?** Render the wrapper `<div>` with a translatable empty message (attribute-overridable) so themes can style the empty state.

---

## Speckit Workflow

```markdown
# 1. Branch
/speckit.git.feature "user-accessible-mcp-servers-shortcode"

# 2. Specify
/speckit.specify "<paste the Detailed Description block below verbatim>"

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
/speckit.git.commit → PR → merge → release chain (version bump + tag + GitHub release)
```

### Detailed Description for `/speckit.specify`

> **Before writing a single line of code, read and internalize all five of these governing documents in full:**
>
> 1. `AGENTS.md` — singleton pattern (A2 / boot-flow.md), Loader-only hook registration in `Main.php` (A1), text domain `'acrossai-mcp-manager'`, Before Commit Checklist.
> 2. This planning brief — `docs/planings-tasks/038-user-accessible-mcp-servers-shortcode.md`.
> 3. **Pattern references** — three files F038 composes (never re-implements):
>    - `includes/AccessControl/AcrossAI_MCP_Access_Control.php:247-276` — `user_has_server_access( int $user_id, int $server_id ): bool` fail-open semantics.
>    - `includes/Embeds/AbstractEmbedTransport.php:185-250 + 267-290` — `get_all_registered_transports()` canonical enumeration + `is_enabled_for_server()` two-check gate with R2 memoization.
>    - `public/Renderers/EmbedBlock/EmbedBlockRenderer.php` — the F037 sibling shortcode; F038 shares its escape-at-render discipline, its anonymous-user silent no-render, and its `add_shortcode` wiring passing through `Main::define_public_hooks()` per A1.
> 4. **Data source** — `public/Discovery/ConnectionMethodRegistry.php` (F035). Every DTO F038 surfaces comes from `$transport->get_dtos()`, which each built-in transport already routes through the registry.
> 5. **Constitutional decision governing this file's location + policy** — `DEC-CLIENT-RENDERER-PUBLIC-API` in `docs/memory/DECISIONS.md`. Both new classes live under `public/Renderers/UserServers/` and are marked `@experimental May change without notice before 1.0.0`; the DTO/data shape freezes at 1.0.0.
>
> Every decision — method signature shape, DTO field names, filter names, singleton vs. static-service — MUST be justified against the above. If a choice is not explicitly covered, default to the sibling `EmbedBlockRenderer` / `MCPClientsBlock` shape. Do not write code that would fail any Definition-of-Done gate: PHPStan level 8, PHPCS, all `__()` calls using the correct text domain.
>
> **Public API artifacts F038 introduces (grep-gate at end — MUST be the ONLY new symbols in the source tree):**
>
> - `\AcrossAI_MCP_Manager\Public\Renderers\UserServers\AbstractUserServersRenderer::get_accessible_servers( ?int $user_id = null ): array`
> - `\AcrossAI_MCP_Manager\Public\Renderers\UserServers\UserServersBlock::instance()`
> - `\AcrossAI_MCP_Manager\Public\Renderers\UserServers\UserServersBlock::register_shortcode(): void`
> - `\AcrossAI_MCP_Manager\Public\Renderers\UserServers\UserServersBlock::render_shortcode( $atts_raw ): string`
> - Shortcode tag `acrossai_mcp_servers`
> - Filter `acrossai_mcp_user_accessible_servers` (fired ONCE inside `get_accessible_servers()` on the assembled data — `array $data, int $user_id`)
> - Filter `acrossai_mcp_servers_shortcode_html` (fired ONCE inside `render_shortcode()` on the final HTML — `string $html, array $data, array $atts`)
>
> **Public API artifacts F038 depends on (grep-gate before start — must be present on `main`):**
>
> - `\AcrossAI_MCP_Manager\Includes\AccessControl\AcrossAI_MCP_Access_Control::user_has_server_access` (F015 / F032)
> - `\AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query::instance` (F011)
> - `\AcrossAI_MCP_Manager\Includes\Embeds\AbstractEmbedTransport::get_all_registered_transports` (F037)
> - `\AcrossAI_MCP_Manager\Includes\Embeds\AbstractEmbedTransport::is_enabled_for_server` (F037)
> - Every built-in transport's `get_dtos()` — routes through F035 `ConnectionMethodRegistry`
>
> Pre-flight grep (records current usage patterns callers may already have):
>
> ```bash
> grep -rEn 'user_has_server_access|get_all_registered_transports|is_enabled_for_server' \
>     --include='*.php' \
>     includes/ admin/ public/ tests/
> ```
>
> Hits inside plugin source are informational — they identify the current gate-cascade consumers F038 composes for its own enumeration. No caller migration is in F038 scope; existing paths keep working.
>
> ---
>
> **TASK-1 — Add `AbstractUserServersRenderer` (data-only base)**
>
> Files:
> - `public/Renderers/UserServers/AbstractUserServersRenderer.php` (NEW)
> - `tests/phpunit/Public/Renderers/UserServers/AbstractUserServersRendererTest.php` (NEW)
>
> Namespace: `AcrossAI_MCP_Manager\Public\Renderers\UserServers`. Class is `abstract`, `@experimental May change without notice before 1.0.0`. No singleton (base is stateless — concrete children pick singleton if they want). No constructor with side effects.
>
> One public method:
>
> ```php
> public function get_accessible_servers( ?int $user_id = null ): array
> ```
>
> Behavior (in order):
>
> 1. Resolve `$user_id` — default `get_current_user_id()`. If `<= 0` (anonymous), return `[]` immediately.
> 2. Enumerate enabled servers: `MCPServerQuery::instance()->query( [ 'is_enabled' => 1, 'number' => -1 ] )`. On empty result, return `[]`.
> 3. For each server row:
>    a. **Access-control gate:** `AcrossAI_MCP_Access_Control::instance()->user_has_server_access( $user_id, (int) $row->id )`. Skip on `false`. (Fail-open when wpb-access-control absent is inside the wrapper.)
>    b. **Transport enumeration:** iterate `AbstractEmbedTransport::get_all_registered_transports()` (already sorted by priority). For each transport:
>       - Iterate `$transport->get_dtos()`.
>       - For each DTO: keep iff `is_string( $dto['slug'] ?? null )` AND `AbstractEmbedTransport::is_enabled_for_server( $server_id, $transport->get_transport_key(), (string) $dto['slug'] )` returns `true`. `is_enabled_for_server` internally checks the master `_embeds_enabled` toggle + per-DTO presence and is per-request memoized — no need to short-circuit the master check separately at the server level.
>    c. If no DTOs survive across any transport for this server, drop the server from the result.
> 4. Assemble `$data` as an ordered list — sort criterion: server row's `server_name` (case-insensitive). Each entry:
>    ```php
>    [
>        'server_id'   => (int),
>        'server_slug' => (string),
>        'server_name' => (string),
>        'description' => (string),
>        'transports'  => [
>            [
>                'key'      => 'client' | 'npm' | 'ai_connector',
>                'label'    => (string, translated, from get_checkbox_label()),
>                'priority' => (int),
>                'dtos'     => [ [ 'slug' => ..., 'name' => ..., 'icon' => ..., 'description' => ..., 'meta' => [...] ], ... ],
>            ],
>            // transports in priority ASC order (already sorted by get_all_registered_transports)
>        ],
>    ]
>    ```
> 5. Fire `apply_filters( 'acrossai_mcp_user_accessible_servers', $data, $user_id )` and return the filter output.
>
> Test additions:
> - Anonymous user (`$user_id = 0` or `get_current_user_id() === 0`) → `[]`.
> - No servers in DB → `[]`.
> - Server exists but `_embeds_enabled` never set → server dropped.
> - Server exists + master ON + zero DTOs in `_embeds_clients` → server dropped.
> - Server exists + master ON + one MCP-client DTO enabled → server included with exactly that DTO.
> - Server exists + master ON + DTOs enabled BUT F015 denies user access → server dropped. Includes fail-open path when the wpb-access-control package is unavailable (returns `true` → server included).
> - Filter round-trip: hook `acrossai_mcp_user_accessible_servers`, mutate the array, assert `get_accessible_servers()` returns the mutated value.
>
> Do NOT include any rendering method. Do NOT include `add_shortcode`. Do NOT hard-depend on WP globals besides `get_current_user_id()`.
>
> ---
>
> **TASK-2 — Add `UserServersBlock` (concrete shortcode child)**
>
> Files:
> - `public/Renderers/UserServers/UserServersBlock.php` (NEW)
> - `tests/phpunit/Public/Renderers/UserServers/UserServersBlockTest.php` (NEW)
>
> Namespace: `AcrossAI_MCP_Manager\Public\Renderers\UserServers`. Class is `final`, extends `AbstractUserServersRenderer`, singleton via `instance()` per A2, private constructor, class docblock `@experimental May change without notice before 1.0.0`.
>
> Public methods:
>
> - `public function register_shortcode(): void` — single-purpose:
>   ```php
>   add_shortcode( 'acrossai_mcp_servers', [ $this, 'render_shortcode' ] );
>   ```
>   This is the ONLY `add_shortcode()` call in the new subsystem. Wiring (see TASK-3) fires it on `init`.
>
> - `public function render_shortcode( $atts_raw ): string` — the shortcode callback. Steps:
>   1. `shortcode_atts()` normalize:
>      - `heading` (default `''` — no header)
>      - `show_description` (default `'1'`)
>      - `empty_message` (default `__( 'You do not have access to any MCP server yet.', 'acrossai-mcp-manager' )`)
>   2. Silent no-render for anonymous: `if ( 0 === get_current_user_id() ) return '';`.
>   3. Fetch data: `$data = $this->get_accessible_servers();`.
>   4. Emit inline scoped `<style>` block **once per request** via a private static `$style_emitted` flag. Class prefix `acrossai-mcp-servers` — never leak generic selectors. Rules cover: outer wrapper, server list gap, per-server card padding/border/radius, transport section header size, DTO row flex + icon inline-block. Minimal, no theme opinions.
>   5. Build HTML from `$data` — the DOM shape below. Escape at boundary via `esc_html`, `esc_attr`, `esc_url` — same discipline as `EmbedBlockRenderer::render_shortcode()`. Wrap the whole thing in a single `<div class="acrossai-mcp-servers">`. If `$data` is empty, emit `<div class="acrossai-mcp-servers acrossai-mcp-servers--empty"><p>…$empty_message…</p></div>`.
>   6. Fire `apply_filters( 'acrossai_mcp_servers_shortcode_html', $html, $data, $atts )` and return the filter output.
>
> DOM shape:
>
> ```html
> <div class="acrossai-mcp-servers">
>   <ul class="acrossai-mcp-servers__list">
>     <li class="acrossai-mcp-servers__server" data-server-id="1" data-server-slug="mcp-adapter-default-server">
>       <h3 class="acrossai-mcp-servers__server-name">Default MCP Server</h3>
>       <p class="acrossai-mcp-servers__server-desc">Optional description</p>
>       <div class="acrossai-mcp-servers__transports">
>         <section class="acrossai-mcp-servers__transport" data-key="client">
>           <h4 class="acrossai-mcp-servers__transport-label">MCP Clients</h4>
>           <ul class="acrossai-mcp-servers__dtos">
>             <li class="acrossai-mcp-servers__dto" data-slug="claude-desktop">
>               <span class="acrossai-mcp-servers__icon">🤖</span>
>               <span class="acrossai-mcp-servers__name">Claude Desktop</span>
>             </li>
>           </ul>
>         </section>
>       </div>
>     </li>
>   </ul>
> </div>
> ```
>
> Icons: if the DTO's `icon` value looks like a URL (`is_string` + starts with `http:` / `https:`), emit an `<img src="…" alt="">` via `esc_url`; otherwise emit as `esc_html` text (F035 DTOs may carry emoji or short markers). Match how `EmbedBlockRenderer` handles the same field.
>
> Test additions:
> - Render smoke: shortcode with default atts + one enabled server → wrapper `<div>` present + one `<li class="acrossai-mcp-servers__server">` + one DTO row.
> - Empty state: no accessible servers → wrapper `<div class="… --empty">` + `<p>` containing the default empty message.
> - Anonymous: `wp_set_current_user( 0 )` → returns `''`.
> - Filter round-trip: hook `acrossai_mcp_servers_shortcode_html`, prepend an HTML comment, assert it appears in the returned string.
> - Style-emitted-once: call `render_shortcode()` twice in one request → `<style>` block appears exactly once (regex-count on the returned strings).
>
> Do NOT enqueue an external CSS file. Do NOT ship JS. Do NOT hook `wp_enqueue_scripts` — inline `<style>` inside the shortcode output only.
>
> ---
>
> **TASK-3 — Wire in `Main::define_public_hooks()` per A1**
>
> Files:
> - `includes/Main.php` (edit — one insertion inside `define_public_hooks()`)
>
> Insert alongside the existing `$embed_renderer` block near line 758:
>
> ```php
> $user_servers_block = \AcrossAI_MCP_Manager\Public\Renderers\UserServers\UserServersBlock::instance();
> $this->loader->add_action( 'init', $user_servers_block, 'register_shortcode' );
> ```
>
> Do NOT add any other hook (no `wp_enqueue_scripts`, no `rest_api_init`, no `admin_*`). Do NOT touch any file other than `Main.php` for wiring.
>
> ---
>
> **TASK-4 — Register `user-servers` PHPUnit suite + CI step**
>
> Files:
> - `phpunit.xml.dist` (add new `<testsuite name="user-servers">` entry)
> - `.github/workflows/phpunit.yml` (add new step running the user-servers suite via `tests/bootstrap-wp.php`)
>
> Follow the F036 test-infrastructure precedent — suite pointing at `tests/phpunit/Public/Renderers/UserServers/`, matching CI step. Verify the suite runs green (with TASK-1 + TASK-2 tests present).
>
> ---
>
> **TASK-5 — Third-party extensibility validation**
>
> Files:
> - `tests/phpunit/Public/Renderers/UserServers/ThirdPartyExtensibilityTest.php` (NEW)
>
> Two scenarios end-to-end:
>
> 1. **BuddyPress-style consumer as data source**: subclass `AbstractUserServersRenderer` inline in the test, call `get_accessible_servers()`, assert the returned array shape matches the specification in TASK-1. No shortcode involvement.
> 2. **BuddyPress-style consumer as HTML customizer**: hook `acrossai_mcp_servers_shortcode_html` to wrap the output in a `<div class="bp-mcp-tab">` — assert wrapping applied. This proves companion plugins can override markup without subclassing.
>
> Also add a smoke test that a companion plugin can register a **fake fourth transport** via `acrossai_mcp_embed_transports` and have its DTOs surface in the F038 payload with zero code changes to F038 (proves the composition is truly filter-driven).
>
> ---
>
> **TASK-6 — Memory hygiene**
>
> Files:
> - `docs/memory/DECISIONS.md` (append)
> - `docs/memory/INDEX.md` (register the new decision entry)
> - `README.txt` (append changelog entry to `= Unreleased =`)
>
> Capture a new decision entry:
>
> - **ID**: next available `D` slot (D38 if D37 is the current tail).
> - **Title**: `User-scoped enumeration primitives compose existing gates — never re-implement them`.
> - **Body**: When a subsystem exposes a "list what the current user can reach" primitive, it MUST compose the shipping gate stack (`user_has_server_access`, `is_enabled_for_server`) — never re-read the underlying meta rows or re-check the wpb-access-control provider list itself. This preserves the fail-open contract (Q2), the R2 memoization (F037), and the admin-bypass hierarchy (F015 v2). F038 established this by wrapping F015 + F037 with a single ordered pass and by exposing `get_accessible_servers()` as data-only so companion plugins can re-render however they like. Rejected alternative: inline the meta reads for "performance" — creates two enumeration paths that will drift (the exact class of bug F035 fixed for MCP clients).
>
> Companion `INDEX.md` row per FR-025.
>
> Changelog entry (`README.txt` under `= Unreleased =`):
>
> ```markdown
> * **Feature 038 — User-accessible MCP servers shortcode + reusable base class.** New shortcode `[acrossai_mcp_servers]` lists every MCP server the current logged-in user can reach (F015 access-control gate) whose F037 Embeds tab has the master toggle ON and at least one enabled connection method — surfacing per server every enabled NPM / MCP Client / AI Connector DTO from F035. Ships with a data-only abstract base class `\AcrossAI_MCP_Manager\Public\Renderers\UserServers\AbstractUserServersRenderer` under `public/Renderers/UserServers/` so companion plugins (planned BuddyBoss add-on, WooCommerce My Account, WPUM, MemberPress) can subclass and consume the enumeration primitive `get_accessible_servers( ?int $user_id = null ): array` without re-implementing the gate cascade. Two new extension filters: `acrossai_mcp_user_accessible_servers` (reshape the payload per context) and `acrossai_mcp_servers_shortcode_html` (override markup without subclassing). Pure composition on top of F011 + F015 + F035 + F037; zero new DB tables / REST endpoints / admin surfaces. `@experimental` per `DEC-CLIENT-RENDERER-PUBLIC-API` — data shape freezes at 1.0.0.
> ```
>
> ---
>
> **Definition of Done gates:**
>
> - PHPStan level 8 + PHPCS individually green on every touched file per task.
> - `vendor/bin/phpunit --testsuite=user-servers` green.
> - No regressions in existing suites (`discovery`, `embeds`, `mcpclients`, `abilities`, `oauth`, etc.).
> - Grep gate: no `apply_filters( 'acrossai_mcp_embed_transports'` inside `public/Renderers/UserServers/` (owned by `AbstractEmbedTransport` — F038 delegates, never re-fires).
> - Grep gate: no `apply_filters( 'acrossai_mcp_client_classes'` inside `public/Renderers/UserServers/` (owned by `AbstractMCPClient::get_all_registered_clients()`).
> - Grep gate: no direct read of `_embeds_enabled` / `_embeds_clients` meta keys inside `public/Renderers/UserServers/` (must route through `AbstractEmbedTransport::is_enabled_for_server` — preserves R2 memoization + gate cascade).
> - Every `__()` call uses `'acrossai-mcp-manager'` text domain.
> - `\AcrossAI_MCP_Manager\Public\Renderers\UserServers\*` symbols do NOT appear anywhere under `includes/` (public → includes is a one-way dependency).

---

## TASK-1 — `AbstractUserServersRenderer` (data-only base)

- New file `public/Renderers/UserServers/AbstractUserServersRenderer.php` per the shape above.
- Public method `get_accessible_servers( ?int $user_id = null ): array` composing the F015 + F037 gate cascade + F035 DTO surface. Fires `acrossai_mcp_user_accessible_servers` once on the assembled data.
- No rendering. No singleton. No hook registration. Class is `abstract` + `@experimental`.

## TASK-2 — `UserServersBlock` (concrete shortcode child)

- New file `public/Renderers/UserServers/UserServersBlock.php` extending the base. Singleton per A2, `final`, `@experimental`.
- `register_shortcode()` — one `add_shortcode()` call for `acrossai_mcp_servers`.
- `render_shortcode()` — anonymous short-circuit, calls parent `get_accessible_servers()`, emits inline `<style>` once per request, renders the DOM shape above with escape-at-boundary discipline, fires `acrossai_mcp_servers_shortcode_html`.

## TASK-3 — Wire in `Main::define_public_hooks()`

- Single `$this->loader->add_action( 'init', $user_servers_block, 'register_shortcode' );` insertion. No other edit to `Main.php`. A1-compliant.

## TASK-4 — PHPUnit suite + CI

- Add `user-servers` suite to `phpunit.xml.dist` pointing at `tests/phpunit/Public/Renderers/UserServers/`. Add matching CI step to `.github/workflows/phpunit.yml` using `tests/bootstrap-wp.php` (touches WP options, BerlinDB, F015 access control stubs).

## TASK-5 — Third-party extensibility validation

- Two-scenario PHPUnit test: (a) subclass as data-only consumer, (b) filter-based HTML customizer. Plus a fake-fourth-transport smoke test proving F038 is truly filter-driven — no code changes required to surface a companion plugin's transport.

## TASK-6 — Memory hygiene

- New `D38` decision entry (user-scoped enumeration primitives compose existing gates — never re-implement).
- Companion `INDEX.md` row.
- `README.txt` `= Unreleased =` changelog bullet.

---

## Manual Verification Checklist

### TASK-1 — `AbstractUserServersRenderer`
- [ ] File exists at `public/Renderers/UserServers/AbstractUserServersRenderer.php`.
- [ ] Class is `abstract`, namespace `AcrossAI_MCP_Manager\Public\Renderers\UserServers`, class docblock includes `@experimental`.
- [ ] `get_accessible_servers( ?int $user_id = null ): array` present with the return shape from TASK-1.
- [ ] Anonymous → `[]`. No enabled server → `[]`. Access-denied server dropped. Server with zero enabled DTOs dropped.
- [ ] Filter `acrossai_mcp_user_accessible_servers` fires exactly once per call on the assembled data.

### TASK-2 — `UserServersBlock`
- [ ] File exists at `public/Renderers/UserServers/UserServersBlock.php`.
- [ ] Class is `final`, singleton per A2, extends `AbstractUserServersRenderer`.
- [ ] `register_shortcode()` calls `add_shortcode( 'acrossai_mcp_servers', [ $this, 'render_shortcode' ] )` — nothing else.
- [ ] `render_shortcode()` returns `''` for anonymous. Emits `<style>` block once per request. Escape-at-boundary on every user-supplied string. Fires `acrossai_mcp_servers_shortcode_html` on the final HTML.

### TASK-3 — `Main.php` wiring
- [ ] `Main::define_public_hooks()` contains the single-line insertion adding the `init` action for `register_shortcode`.
- [ ] Grep gate: `grep -rn 'add_shortcode' public/Renderers/UserServers/` returns exactly one hit (inside `UserServersBlock::register_shortcode`).
- [ ] `Main.php` diff is a single insertion — no other edit.

### TASK-4 — PHPUnit suite + CI
- [ ] `phpunit.xml.dist` has a `<testsuite name="user-servers">` entry pointing at `tests/phpunit/Public/Renderers/UserServers/`.
- [ ] `.github/workflows/phpunit.yml` has a matching step running the suite via `tests/bootstrap-wp.php`.
- [ ] Suite runs green locally (`vendor/bin/phpunit --testsuite=user-servers`) and on CI.

### TASK-5 — Third-party extensibility validation
- [ ] Data-only subclass consumer returns expected array shape.
- [ ] `acrossai_mcp_servers_shortcode_html` filter wraps output when a hook is registered.
- [ ] Fake fourth transport registered via `acrossai_mcp_embed_transports` surfaces in `get_accessible_servers()` with zero F038 code changes.

### End-to-end smoke test (local install)
- [ ] Log in as admin. Create a page containing only `[acrossai_mcp_servers]`. With no server having `_embeds_enabled = 1`, page renders the wrapper with the empty message.
- [ ] Enable Embeds on server #1 → Claude Desktop + NPM only. Reload page → both DTOs listed under server #1, AI Connectors section absent.
- [ ] Enable a second server, restrict its Access Control tab to `administrator` role → still visible to admin.
- [ ] Log out. Set the server's Access Control to allow ONLY `editor`. As subscriber → server hidden. As editor → visible.
- [ ] Delete a server via the admin list. Reload page → server drops silently (F037 cleanup already prunes meta rows via `Main::cleanup_embed_transports_on_server_delete`).
- [ ] Attribute test: `[acrossai_mcp_servers empty_message="Nothing here yet"]` → custom message renders when empty.

### F015 fail-open cascade
- [ ] Temporarily rename `WPBoilerplate\AccessControl\AccessControlManager` in vendor autoload map (or comment out `boot_manager()`). Shortcode still returns servers gated only by the embeds toggle — same fail-open the F015 wrapper promises.

### Grep gates (blocker before merge)
- [ ] `grep -rn "apply_filters.*acrossai_mcp_embed_transports" public/Renderers/UserServers/` returns zero hits (delegate, don't re-fire).
- [ ] `grep -rn "apply_filters.*acrossai_mcp_client_classes" public/Renderers/UserServers/` returns zero hits.
- [ ] `grep -rn "_embeds_enabled\|_embeds_clients" public/Renderers/UserServers/` returns zero hits (route through `is_enabled_for_server`).
- [ ] `grep -rn "UserServers" includes/` returns zero hits (public/ never imported into includes/).

### Quality gates (all must be green before commit)
- [ ] PHPStan level 8 — zero errors on all touched files.
- [ ] PHPCS — zero errors on all touched files.
- [ ] `composer test` — full PHPUnit suite green.
- [ ] `composer dump-autoload` — succeeds with zero warnings.
- [ ] CI green on all required checks + the new user-servers suite step.

---

## Not in scope

- **Block-editor block** — parallel of F037's block-editor deferral. Same data source, same filter, different renderer; a follow-up feature if BuddyBoss / WooCommerce don't cover the gap first.
- **Per-user server pinning / ordering** — MVP orders alphabetically by server name. Custom ordering (drag-and-drop, "favorites first") is a follow-up.
- **Frontend interactivity** — no JS ships in F038. Just data + inline HTML + scoped `<style>`. Any interactive UX (per-server "copy config" button, expand/collapse) is a follow-up or a companion plugin's job.
- **Companion plugin integration (BuddyBoss add-on)** — separate repository. F038's job is to expose the base class + the two filters; the add-on subclasses.

---

## Cross-References

- **F011** — BerlinDB Kern pattern (F038 consumes `MCPServerQuery` unchanged).
- **F015** — Access control wrapper (`AcrossAI_MCP_Access_Control::user_has_server_access`). F038's per-server gate.
- **F032** — Connection-time AC check (introduced `user_has_server_access`). F038 reuses the same helper on a different call site.
- **F034** — `AbstractMCPClient` self-contained-subsystem-contract (D35). F038 mirrors the "consume, never re-implement" discipline.
- **F035** — `ConnectionMethodRegistry` — F038's DTO source (via each transport's `get_dtos()`).
- **F037** — `AbstractEmbedTransport` + Embeds tab + `[acrossai_mcp_embed]` shortcode. F038's per-server per-DTO gate.
