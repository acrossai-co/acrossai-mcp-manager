# Planning: Per-Server Ability Permission-Callback Override (Feature 030)

Replace the vendor `wpb-access-control` React panel on the server-edit "Access
Control" tab (`admin.php?page=acrossai_mcp_manager&action=edit&server={id}&tab=access-control`)
with a single boolean toggle: **"Override all abilities' `permission_callback`
for this MCP server."** When ON, every ability exposed via the
`wp_acrossai_mcp_server_abilities` junction table for this server has its
`permission_callback` overridden to `return true;` at call time — but only for
in-flight MCP REST requests that route to THIS server (server context resolved
via `CurrentServerHolder`). Outside those requests the ability's original
`permission_callback` runs unchanged.

The override is intentionally unconditional (`return true;`) and registered at
`wp_register_ability_args` priority **999999**, i.e. higher than the sibling
`acrossai-abilities-manager` plugin's per-slug injector at P100000 (see
`AcrossAI_Ability_Override_Processor::boot()` line 164) and higher than this
plugin's own `CallbackReplacer` at P10 (see `Main.php:536`). The intent per
product decision is "when the server operator flips the switch, every other
plugin's per-ability permission decision is superseded for THIS server only."
Outside the MCP request lifecycle the closure short-circuits to the original
callback via a captured fallback — this is what preserves the "site-wide
callers still see the ability's original rules" invariant.

The feature also introduces one new schema column
(`override_abilities_permission tinyint(1) NOT NULL DEFAULT 0`) on
`wp_acrossai_mcp_servers` via the D28 BerlinDB drift-reconciliation contract
(precedent: commit `9a5feda`, `Table::upgrade_to_1_1_1()` at
`includes/Database/MCPServer/Table.php:136`). The Table version bumps
`1.1.1 → 1.1.2`; `Main::reconcile_database_schemas()` at `admin_init` P3 fires
the idempotent upgrader on the next admin request.

The existing `AccessControlTab` class stays registered in
`Registry::all_tabs()` — only `render_body()` is rewritten. The
`AccessControlBlock` renderer at `public/Renderers/AccessControlBlock.php`
stays untouched because it is documented as a public API for third-party
plugins consuming the vendor Access Control UI. The
`Main::maybe_enqueue_access_control_app()` React-bundle enqueue becomes dead
code inside the still-guarded `?tab=access-control` route; a follow-up feature
may prune it.

---

## Speckit Workflow

```markdown
# 1. Branch
/speckit.git.feature "per-server-permission-override"

# 2. Specify
/speckit.specify "Replace the vendor wpb-access-control React panel on the
server-edit Access Control tab (?tab=access-control) with a single boolean
toggle labelled 'Override all abilities' permission_callback for this MCP
server'. Persist the toggle in a new tinyint(1) column
override_abilities_permission on wp_acrossai_mcp_servers via the D28 BerlinDB
drift-reconciliation contract: bump Table $version 1.1.1 → 1.1.2, register an
idempotent upgrade_to_1_1_2 callback using INFORMATION_SCHEMA.COLUMNS to guard
the ALTER, add the property + tinyint cast to Row. Wire the save path through
a new dedicated handler (nonce action
acrossai_mcp_manager_permission_override_{server_id}, capability
manage_options) that calls
MCPServerQuery::instance()->update_item( $server_id,
[ 'override_abilities_permission' => 0|1 ] ) and redirects back to the tab
with a success notice — do NOT bolt the field onto handle_update_server()
because that owns the Update Server tab. Ship a new
includes/Abilities/PermissionOverrideProcessor.php following the plugin
singleton pattern that hooks wp_register_ability_args at priority 999999 and
wraps every ability's permission_callback in a closure. The closure captures
the original callback and, at call time, resolves the current MCP server via
CurrentServerHolder::instance()->get_server_id(); returns the original
callback's result when server context is null or when override is 0 for that
server or when ExposureResolver::resolve( $server_id, $slug, $args['meta'] ??
[] ) is false; returns true unconditionally otherwise. Boot the processor
from Main.php next to CallbackReplacer wiring (around Main.php:536). Keep
AccessControlTab registered in Registry::all_tabs() — only rewrite its
render_body() to render the new checkbox form. Do not touch
public/Renderers/AccessControlBlock.php (public third-party API). Do not
touch maybe_enqueue_access_control_app() (becomes dead code in this feature;
pruning is out of scope). Do not touch handle_update_server(). Do not
regress the F015 nonce/capability posture: manage_options + per-server nonce.
Memory hygiene per PATTERN-MEMORY-SUPERSESSION-VS-ANNOTATION: if any
DEC-F015-* / DEC-ACCESS-CONTROL-VENDOR-* decision covers the wpb-ac panel it
is superseded (Feature 030); annotate patterns that survive with
forward-pointer notes."
```

### Detailed Description for `/speckit.specify`

> **Before writing a single line of code, read and internalize all five of
> these governing documents in full:**
>
> 1. `AGENTS.md` — plugin's singleton pattern, hook-registration rule ("only
>    `Main.php` calls `$this->loader->add_action/filter`"), Admin Partials
>    Rule, Before Commit Checklist.
> 2. `docs/memory/DECISIONS.md` §"2026-07-18 — D28 — BerlinDB schema-drift
>    reconciliation" — the 3-part column-add contract (bump `$version`,
>    register idempotent `$upgrades` callback, rely on
>    `Main::reconcile_database_schemas()` at `admin_init` P3).
> 3. `docs/planings-tasks/011-berlindb-migration.md` — the BerlinDB base-class
>    conventions and the phantom-version guard already in place on
>    `MCPServer\Table`.
> 4. `docs/planings-tasks/017-per-server-ability-selection.md` — the
>    `wp_acrossai_mcp_server_abilities` junction table and
>    `ExposureResolver::resolve()` contract (`server_id + ability_slug + meta`
>    → bool exposed).
> 5. `../acrossai-abilities-manager/includes/Modules/Abilities/AcrossAI_Ability_Override_Processor.php`
>    — the **pattern reference** for the override closure. In particular
>    `build_permission_callback()` at line 366: closure captures slug, at call
>    time consults a per-request source of truth, returns a typed bool. Our
>    feature mirrors the shape but replaces the AC-library-backed body with
>    an unconditional `return true;` scoped by `CurrentServerHolder`.
>
> Any decision not explicitly covered by the above defaults to the sibling
> plugin's shape. Do not write code that would fail any Definition-of-Done
> gate: PHPStan L8, PHPCS, security review, all `__()` calls using
> `'acrossai-mcp-manager'`.
>
> **Public API artifacts to preserve verbatim (grep-gate before + after):**
>
> - `\AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\AccessControlTab` (class
>   still registered in `Registry::all_tabs()`; only `render_body()` body
>   changes).
> - `\AcrossAI_MCP_Manager\Public\Renderers\AccessControlBlock` (third-party
>   public API — zero edits).
> - `\AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query` public method
>   signatures.
> - `\AcrossAI_MCP_Manager\Includes\Abilities\CurrentServerHolder::get_server_id()`
>   / `get()` return contracts.
> - `\AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\ExposureResolver::resolve()`
>   signature.
>
> Pre-flight grep (records the callers whose behaviour must be unchanged):
> ```
> grep -rEn '(AccessControlBlock::instance|AccessControlTab|CurrentServerHolder::instance|ExposureResolver::resolve|MCPServerQuery|maybe_enqueue_access_control_app)' \
>     --include='*.php' \
>     includes/ admin/ public/ acrossai-mcp-manager.php
> ```
>
> Preserved table + column map (data-preservation contract):
>
> | Table | `db_version_key` | Current `$version` | New `$version` |
> | --- | --- | --- | --- |
> | `acrossai_mcp_servers` | `wpdb_acrossai_mcp_servers_version` | `1.1.1` | `1.1.2` |
>
> New column added by Feature 030:
>
> | Column | Type | Default | Notes |
> | --- | --- | --- | --- |
> | `override_abilities_permission` | `tinyint(1)` | `0` | Idempotent ALTER via `INFORMATION_SCHEMA.COLUMNS` existence check. |
>
> ---
>
> **TASK-1 — Add `override_abilities_permission` column to `MCPServer`**
>
> Files:
> - `includes/Database/MCPServer/Schema.php` (append one entry to `$columns`)
> - `includes/Database/MCPServer/Table.php` (bump `$version`, add
>   `$upgrades` entry, add `upgrade_to_1_1_2()` method)
> - `includes/Database/MCPServer/Row.php` (add public property + tinyint cast)
>
> Read `MCPServer/Table.php:136-171` (the existing `upgrade_to_1_1_1()`) before
> editing — this is the exact idempotent pattern to mirror.
>
> Schema — append to `$columns`:
> ```php
> array(
>     'name'    => 'override_abilities_permission',
>     'type'    => 'tinyint',
>     'length'  => '1',
>     'default' => 0,
> ),
> ```
>
> Table — bump `$version = '1.1.2';`. Add to `$upgrades`:
> ```php
> '1.1.2' => 'upgrade_to_1_1_2',
> ```
> Implement `upgrade_to_1_1_2()` mirroring `upgrade_to_1_1_1()` verbatim:
> `INFORMATION_SCHEMA.COLUMNS` existence check → return true if present →
> otherwise `$wpdb->query()` the ALTER ADD COLUMN. DDL:
> ```sql
> ALTER TABLE `{$table}` ADD COLUMN `override_abilities_permission` tinyint(1) NOT NULL DEFAULT 0
> ```
>
> Row — add public property `public $override_abilities_permission = 0;` alongside
> the existing `tool_*` properties (lines 22-34). Add `(int)` cast in the
> constructor next to the existing `tool_*` casts.
>
> Do NOT modify `Main::reconcile_database_schemas()` — it already covers
> MCPServer at `admin_init` P3.
>
> ---
>
> **TASK-2 — Rewrite `AccessControlTab::render_body()` + add save handler**
>
> Files:
> - `admin/Partials/ServerTabs/AccessControlTab.php` (rewrite `render_body()`)
> - `admin/Partials/Settings.php` (add new save handler + wire it into the
>   existing admin_init-time router that dispatches server-edit POSTs)
>
> `render_body( array $server )` new body (rough shape):
> - Delete the existing `AccessControlBlock::instance()->render(...)` call.
> - Render a `<div class="wrap acrossai-mcp-permission-override">` container.
> - `<h2>` with `esc_html__( 'Ability Permission Override', 'acrossai-mcp-manager' )`.
> - `<p class="description">` explaining that when checked, every ability
>   exposed to this MCP server will bypass its `permission_callback` for
>   requests arriving via this server's routes. Warn that this SUPERSEDES
>   other plugins' access rules (P999999 > P100000).
> - `<form method="post" action="{ $this->server_edit_url( $server, 'access-control' ) }">`
>   containing:
>   - `wp_nonce_field( 'acrossai_mcp_manager_permission_override_' . (int) $server['id'], 'acrossai_mcp_manager_permission_override_nonce' )`.
>   - `<input type="hidden" name="acrossai_mcp_manager_action" value="save_permission_override">`.
>   - `<input type="hidden" name="server" value="{ (int) $server['id'] }">`.
>   - `<label><input type="checkbox" name="override_abilities_permission" value="1" { checked( (int) $server['override_abilities_permission'], 1, false ) }> { esc_html__( 'Override abilities permission_callback for this MCP server', 'acrossai-mcp-manager' ) }</label>`.
>   - `submit_button( __( 'Save Permission Override', 'acrossai-mcp-manager' ) )`.
>
> Save handler — add `Settings::handle_save_permission_override()` (new method
> mirroring `handle_update_server()` shape but scoped to the one field):
> ```php
> private function handle_save_permission_override(): void {
>     if ( ! current_user_can( 'manage_options' ) ) {
>         wp_die( esc_html__( 'You are not allowed to perform this action.', 'acrossai-mcp-manager' ), '', array( 'response' => 403 ) );
>     }
>     $server_id = isset( $_POST['server'] ) ? absint( wp_unslash( $_POST['server'] ) ) : 0;
>     if ( $server_id <= 0 ) {
>         wp_die( esc_html__( 'Invalid server.', 'acrossai-mcp-manager' ), '', array( 'response' => 400 ) );
>     }
>     check_admin_referer( 'acrossai_mcp_manager_permission_override_' . $server_id, 'acrossai_mcp_manager_permission_override_nonce' );
>
>     $value = ! empty( $_POST['override_abilities_permission'] ) ? 1 : 0;
>     MCPServerQuery::instance()->update_item(
>         $server_id,
>         array( 'override_abilities_permission' => $value )
>     );
>
>     wp_safe_redirect( add_query_arg(
>         array(
>             'page'                                    => 'acrossai_mcp_manager',
>             'action'                                  => 'edit',
>             'server'                                  => $server_id,
>             'tab'                                     => 'access-control',
>             'acrossai_mcp_manager_permission_saved' => 1,
>         ),
>         admin_url( 'admin.php' )
>     ) );
>     exit;
> }
> ```
> Wire it into `Settings::route_post_actions()` (or whatever the existing
> `admin_init` dispatcher is named — see how `handle_update_server()` gets
> called today; mirror that exact wiring, adding a
> `'save_permission_override' => 'handle_save_permission_override'` branch).
>
> Add an `admin_notices` render for the `acrossai_mcp_manager_permission_saved=1`
> query flag — reuse whatever success-notice helper already renders for the
> Update Server tab.
>
> Do NOT stuff the new field into `handle_update_server()`. That method owns
> the Update Server tab; adding a stray field couples two tabs' persistence.
>
> Do NOT delete `AccessControlBlock` or `maybe_enqueue_access_control_app()`.
>
> ---
>
> **TASK-3 — `PermissionOverrideProcessor` + `Main.php` wiring**
>
> Files:
> - `includes/Abilities/PermissionOverrideProcessor.php` (NEW)
> - `includes/Main.php` (delta: add one filter registration near line 536)
>
> Read `../acrossai-abilities-manager/includes/Modules/Abilities/AcrossAI_Ability_Override_Processor.php`
> lines 366-380 (`build_permission_callback()`) before writing — that is the
> pattern being mirrored, with the closure body swapped for
> `CurrentServerHolder`-scoped `return true;`.
>
> New class `AcrossAI_MCP_Manager\Includes\Abilities\PermissionOverrideProcessor`
> follows the plugin's singleton contract (protected `$_instance`, public
> `instance()`, private `__construct()`). Static `boot()` method registers:
> ```php
> add_filter( 'wp_register_ability_args', array( __CLASS__, 'inject_override' ), 999999, 2 );
> ```
>
> `inject_override( array $args, string $slug ): array`:
> ```php
> $original = $args['permission_callback'] ?? null;
>
> $args['permission_callback'] = static function () use ( $slug, $original ) {
>     // Not inside an MCP-server-scoped request → no override; fall through.
>     $server_id = CurrentServerHolder::instance()->get_server_id();
>     if ( null === $server_id ) {
>         return self::call_original( $original );
>     }
>
>     // Read the server row's override flag.
>     $rows = MCPServerQuery::instance()->query( array( 'id' => $server_id, 'number' => 1 ) );
>     if ( empty( $rows ) || 0 === (int) $rows[0]->override_abilities_permission ) {
>         return self::call_original( $original );
>     }
>
>     // Only override abilities that are actually exposed to this server via the junction table.
>     if ( ! ExposureResolver::resolve( $server_id, $slug, array() ) ) {
>         return self::call_original( $original );
>     }
>
>     return true;
> };
>
> return $args;
> ```
>
> Helper `call_original( $original )`:
> ```php
> private static function call_original( $original ) {
>     if ( is_callable( $original ) ) {
>         return call_user_func( $original );
>     }
>     return false;
> }
> ```
> `false` as the fallback matches WP Abilities API semantics when a
> `permission_callback` is absent or non-callable — treat as deny.
>
> `Main.php` — inside the `define_admin_hooks()` (or `define_public_hooks()` —
> whichever registers the existing `CallbackReplacer` filter at line 536),
> add ONE line:
> ```php
> \AcrossAI_MCP_Manager\Includes\Abilities\PermissionOverrideProcessor::boot();
> ```
> This is called directly from `Main::load_hooks()` (NOT via `$this->loader`)
> because `PermissionOverrideProcessor::boot()` registers via `add_filter`
> itself, matching the shape of `AcrossAI_Ability_Override_Processor::boot()`
> in the sibling plugin. Alternative: wire through the Loader as an object +
> method pair (`$this->loader->add_filter( 'wp_register_ability_args',
> PermissionOverrideProcessor::instance(), 'inject_override', 999999, 2 )`).
> Pick whichever the reviewer prefers — the sibling plugin's inline
> `add_filter` inside a `boot()` method is the pattern most directly matched
> by this feature's reference.
>
> Do NOT hook `mcp_adapter_pre_tool_call` — the F017 exposure gate + F020
> curation gate already occupy priorities 20 + 30 on that filter and a
> third gate would confuse the deny-precedence contract. Overriding at
> registration time via `wp_register_ability_args` matches the reference
> `AcrossAI_Ability_Override_Processor` pattern the user explicitly cited.
>
> ---
>
> **TASK-4 — WordPress.org plugin-directory assets**
>
> Files:
> - `.wordpress-org/banner-1544x500.png` (new)
> - `.wordpress-org/banner-772x250.png` (new)
> - `.wordpress-org/icon.svg` (new)
> - `.wordpress-org/screenshot-1.png` … `screenshot-11.png` (new)
>
> These are currently untracked in the working tree. Stage the whole
> `.wordpress-org/` directory via `git add .wordpress-org/` and commit it in
> the same PR as this feature. Add an "Also includes" bullet to the PR
> description so reviewers know why non-code files are in the diff. No plan
> validation needed — these are marketing/directory assets.
>
> ---
>
> **TASK-5 — Memory hygiene + changelog**
>
> Files: `README.txt`, `docs/memory/DECISIONS.md`, `docs/memory/WORKLOG.md`,
> `docs/memory/INDEX.md`, `docs/planings-tasks/README.md`
>
> `README.txt` — add an Unreleased changelog bullet:
> ```
> * The MCP-server-edit "Access Control" tab now hosts a single toggle:
>   when enabled, all abilities exposed to this MCP server bypass their
>   permission_callback for requests to this server's routes. Site-wide
>   ability callers (WP admin, other REST namespaces, WP-CLI) see the
>   original permission_callback unchanged. Filter runs at priority 999999,
>   overriding any prior per-ability access-control decision for that
>   server.
> ```
>
> `docs/memory/DECISIONS.md` — new active entry:
> ```
> DEC-PERMISSION-OVERRIDE-P999999 (Active — Feature 030)
> The per-server ability permission-callback override registers on
> wp_register_ability_args at P999999 — higher than
> AcrossAI_Ability_Override_Processor (P100000) and MCP-Manager
> CallbackReplacer (P10) — so the toggle wins when ON. Trade-off accepted:
> the override is unconditional (return true) and cannot be re-narrowed by
> a lower-priority filter for the same server; this is intentional per
> product decision.
> ```
> Mark any older DEC-F015-* / DEC-ACCESS-CONTROL-VENDOR-* entries that
> describe the retired `wpb-access-control` panel as **Superseded (Feature
> 030)** per PATTERN-MEMORY-SUPERSESSION-VS-ANNOTATION, keeping the entry
> bodies intact.
>
> `docs/memory/WORKLOG.md` — Feature 030 milestone entry (Why durable /
> Future mistake prevented / Evidence / Where to look). Durable lesson:
> **`CurrentServerHolder`-scoped closures are the correct hammer for
> "per-server behavior override" — do NOT branch the injected callback on
> registration-time state, which cannot know which server is currently
> being served.**
>
> `docs/memory/INDEX.md` — add the new DECISION + WORKLOG rows; mark
> superseded rows.
>
> `docs/planings-tasks/README.md` — append a row for
> `030-per-server-permission-override.md`.
>
> ---
>
> **CONSTRAINTS**
>
> - **Do not delete or edit `public/Renderers/AccessControlBlock.php`.** It
>   is a documented third-party public API (see header comment lines 1-15).
>   Feature 030's replacement is scoped to `AccessControlTab::render_body()`.
> - **Do not delete `Main::maybe_enqueue_access_control_app()`.** Becomes
>   dead code (route still guarded by `?tab=access-control` but nothing to
>   mount into). Pruning is out of scope; a future feature can retire the
>   enqueue + the webpack entry together.
> - **Do not add the new field to `handle_update_server()`** in
>   `admin/Partials/Settings.php`. Each tab owns its own save path.
> - **Do not touch `Registry::all_tabs()` or `AccessControlTab::slug()`.**
>   Slug + tab registration stay `'access-control'` byte-for-byte so
>   existing bookmarks and third-party filters referencing that slug do
>   not break.
> - **Do not lower the filter priority.** P999999 is chosen so
>   AcrossAI_Ability_Override_Processor (P100000) cannot post-modify our
>   callback and re-inject its own — the override toggle must be the last
>   word for THIS server.
> - **Do not remove the `CurrentServerHolder` guard.** Site-wide ability
>   consumers (non-MCP REST callers, WP admin, WP-CLI) MUST see the
>   original `permission_callback`. The `null === $server_id` early return
>   is the invariant that makes this true.
> - **Do not skip `ExposureResolver::resolve()`.** Only abilities actually
>   exposed to this server via `wp_acrossai_mcp_server_abilities` are
>   overridden. Abilities that happen to be registered on the site but not
>   exposed to this server keep their original callback even during a
>   scoped MCP request.
> - **Do not add data migration.** The new column defaults to `0` — every
>   existing server row is implicitly "override OFF", preserving prior
>   behaviour byte-for-byte on upgrade.
> - **Every task must leave PHPStan level 8 + PHPCS individually green
>   before moving to the next.** Constitution §VII per-task gating applies.
> - **BerlinDB Schema `$columns` MUST match `upgrade_to_1_1_2()`'s DDL
>   byte-for-byte** (type, length, default, nullability). BerlinDB's diff
>   engine will fire ALTER on production installs otherwise.

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

### TASK-1 — Schema + Table + Row column add
- [ ] `SELECT option_value FROM wp_options WHERE option_name = 'wpdb_acrossai_mcp_servers_version'` returns `1.1.2` after loading any admin page (triggers `admin_init` P3 reconciliation).
- [ ] `DESCRIBE wp_acrossai_mcp_servers` lists `override_abilities_permission tinyint(1) NOT NULL DEFAULT '0'` as the last column.
- [ ] Re-running the upgrader (deactivate/reactivate) does not re-issue the ALTER (verify `SHOW WARNINGS;` empty and `debug.log` silent between activations).
- [ ] `MCPServerQuery::instance()->query( [ 'id' => 1, 'number' => 1 ] )[0]->override_abilities_permission` returns `int 0` (BerlinDB tinyint quirk properly cast by `Row::__construct()`).

### TASK-2 — Access Control tab UI + save handler
- [ ] Visit `admin.php?page=acrossai_mcp_manager&action=edit&server=1&tab=access-control` — old `<div class="wpb-ac">…</div>` React panel is GONE. New checkbox form renders.
- [ ] Check the box + click Save. Reload the page. Checkbox is still checked. DB confirms: `SELECT override_abilities_permission FROM wp_acrossai_mcp_servers WHERE id = 1` returns `1`.
- [ ] Uncheck + Save + reload. Checkbox unchecked. DB returns `0`.
- [ ] Submit the form with a stale nonce (open a second tab, log out & log in again, submit the first tab). Save should `wp_die` with a nonce error — no DB write.
- [ ] Submit the form as a user without `manage_options`. Save should `wp_die` with 403 — no DB write.
- [ ] `admin.php?page=acrossai_mcp_manager&action=edit&server=1&tab=update-server` renders unchanged (proof that `handle_update_server()` was not touched).

### TASK-3 — Runtime override
- [ ] With `override_abilities_permission = 1` on server 1 and `= 0` on server 2, ability `foo/bar` exposed to both servers:
  - Call `foo/bar` via server 1's MCP route as a user WITHOUT the ability's normal cap — succeeds (returns tool result, not 401/403).
  - Call `foo/bar` via server 2's MCP route as the same user — fails per the ability's original `permission_callback`.
  - Call `foo/bar` directly via the WP Abilities REST route (`/wp/v2/abilities/foo/bar`) as the same user — original `permission_callback` verdict (deny).
- [ ] With `override_abilities_permission = 1` on server 1 but ability `foo/qux` NOT exposed via `wp_acrossai_mcp_server_abilities` — MCP request through server 1 that (somehow) reaches `foo/qux` still enforces the original callback (proof `ExposureResolver::resolve()` gate works).
- [ ] Sibling `acrossai-abilities-manager` plugin active AND an AC rule denying `foo/bar` for the current user: with server 1's override ON, the request still succeeds (proof P999999 > P100000).
- [ ] Sibling plugin's AC rule denial is respected on non-MCP REST routes (proof site-wide callers are unaffected).

### TASK-4 — WordPress.org assets
- [ ] `git ls-files .wordpress-org/` lists all 14 assets (2 banners, 1 icon.svg, 11 screenshots).

### TASK-5 — Release notes + memory hygiene
- [ ] `README.txt` Unreleased changelog contains the Feature 030 bullet.
- [ ] `docs/memory/DECISIONS.md`: `DEC-PERMISSION-OVERRIDE-P999999` present with body above; any retired `DEC-F015-*` / `DEC-ACCESS-CONTROL-VENDOR-*` marked Superseded (Feature 030) with body intact.
- [ ] `docs/memory/WORKLOG.md`: Feature 030 milestone entry added.
- [ ] `docs/memory/INDEX.md`: new + Superseded rows updated.
- [ ] `docs/planings-tasks/README.md` lists `030-per-server-permission-override.md`.

### Final full-repo audit (blocker before merge)

```bash
grep -rEn 'wpb-access-control|acrossai-mcp-ac-root|AccessControlBlock::instance' \
    --include='*.php' \
    includes/ admin/ public/ acrossai-mcp-manager.php
```

- [ ] `AccessControlBlock::instance` matches — expected: 0 (removed from `AccessControlTab::render_body()`; the block class itself defines but no longer self-references from admin).
- [ ] `wpb-access-control` / `acrossai-mcp-ac-root` in ADMIN paths — expected: 0 (renderer keeps them for third-party callers; admin no longer emits them).

### Quality gates (all must be green before commit)
- [ ] PHPStan level 8 — zero errors on all touched files.
- [ ] PHPCS — zero errors on all touched files.
- [ ] `composer test` — PHPUnit passes; add coverage for `PermissionOverrideProcessor::inject_override()` scenarios (server-context-null fall-through; override-off fall-through; not-exposed fall-through; override-on-exposed returns true).
- [ ] `SELECT COUNT(*) FROM wp_acrossai_mcp_servers WHERE override_abilities_permission NOT IN (0, 1)` returns 0.
