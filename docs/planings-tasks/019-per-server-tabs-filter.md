# Planning: Third-party filter for per-server tabs (Feature 019)

Open the per-server Edit MCP Server page (`?page=acrossai_mcp_manager&action=edit&server=N`) to third-party tab contribution via a new WordPress filter, without touching the tab UX, the URL scheme, or the class hierarchy that Feature 013 established. Companion plugins can add a tab, remove a built-in, reorder the list, or swap a built-in's label — all via a single `apply_filters( 'acrossai_mcp_manager_server_tabs', $tabs, $server )` seam in `admin/Partials/ServerTabs/Registry.php`.

The current state is that `Registry::all_tabs()` returns a hardcoded `AbstractServerTab[]` of 10 entries. There is no filter, no action hook, and no way for a companion plugin to contribute a tab except by monkey-patching the Registry class (which the plugin's autoloader precludes). Feature 019 removes that limitation.

The design mirrors the vendor `acrossai-co/main-menu` `acrossai_settings_tabs` model shipped in `0.0.13` — array-based entries with `[slug, label, priority, capability, render_callback, visible_callback]` — so third-party plugin authors already familiar with the vendor's Settings tab extension model can use the same shape here. The extension surface is deliberately **array-based, not class-based**: companion plugins do NOT need to load `\AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\AbstractServerTab` to contribute a tab. Internally, a new `FilteredServerTab` adapter wraps each contributed entry so the rest of the plugin's dispatch pipeline (`Registry::render()`, `Settings::render_edit_page()`, `SettingsRenderer::render_tab_nav()`) treats third-party tabs identically to built-ins.

The change is **fully backwards-compatible**: every built-in tab keeps its class file, slug, and label byte-for-byte. `Registry::all_tabs()` continues to return the same 10 built-ins in the same order. The new filter fires inside a new `Registry::for_server( array $server )` method that is what `Registry::visible_tabs()` and `Registry::render()` internally delegate to — the public signatures of both are unchanged. The `visible_for()` gating on `UpdateServerTab` and `DangerZoneTab` (database-source only) remains authoritative.

The migration is **not backwards-compatible with any prior "extension" attempt** because no such extension surface existed prior to Feature 019. This is a net-new API.

---

## Speckit Workflow

```markdown
# 1. Branch
/speckit.git.feature "per-server-tabs-filter"

# 2. Specify
/speckit.specify "Add a WordPress filter to admin/Partials/ServerTabs/Registry.php
so that third-party plugins can add, remove, reorder, or re-gate tabs on the
per-server Edit MCP Server page (?page=acrossai_mcp_manager&action=edit&server=N).
Filter name: 'acrossai_mcp_manager_server_tabs'. Signature:
apply_filters( 'acrossai_mcp_manager_server_tabs', array $tabs, array $server ):
array — first argument is the normalized list of entries for the currently
edited server, second argument is the server row array. Each entry is an
associative array with keys slug (required, sanitize_key applied),
label (required, string), priority (optional int, built-ins slotted at
10/20/30/40/50/60/70/80/90/100 so third parties can slot between them; default
100 for entries missing priority), capability (optional string, default
'manage_options'), render_callback (required callable receiving the $server
array; echoes tab body), visible_callback (optional callable receiving
$server; returns bool; supplements the capability check). Normalization loop
mirrors vendor TabbedPageRenderer::resolve_tabs() — sanitize_key on slug,
first-registration-wins dedup, _doing_it_wrong under WP_DEBUG for malformed
entries. Add an internal FilteredServerTab adapter extending AbstractServerTab
that wraps a contributed entry; its render_body() invokes the entry's
render_callback inside a try/catch \\Throwable boundary that logs to
error_log and echoes an inline admin-notice error, mirroring the
safeApplyFilters pattern from Feature 017's src/js/abilities.js — so a broken
companion plugin cannot white-screen the Edit MCP Server page. Add a
priority(): int method with a default 100 on AbstractServerTab; override on
the ten built-in tab classes (OverviewTab=10, NpmTab=20, ClientsTab=30,
WpCliTab=40, ToolsTab=50, AbilitiesTab=60, AccessControlTab=70,
McpTrackerTab=80, UpdateServerTab=90, DangerZoneTab=100). Do NOT change
existing tab class slugs, labels, visible_for() implementations, or render
bodies. Do NOT change the public signatures of Registry::all_tabs(),
Registry::visible_tabs(), or Registry::render(). Do NOT change the URL scheme
emitted by SettingsRenderer::render_tab_nav() — it continues to include
action=edit&server=N. Do NOT alter Settings::render_edit_page(). Do NOT
allow a third-party entry to override or clobber a built-in tab's slug —
first-registration-wins dedup, seeded with the ten built-ins first."
```

### Detailed Description for `/speckit.specify`

> **Before writing a single line of code, read and internalize all four of
> these governing documents in full:**
>
> 1. `AGENTS.md` — this plugin's singleton pattern, hook registration rules,
>    Before Commit Checklist.
> 2. `docs/planings-tasks/013-per-server-tabs-refactor.md` — established
>    `AbstractServerTab` / `Registry` / per-tab class hierarchy and the
>    `DEC-SERVER-TAB-CLASS-HIERARCHY` decision. Feature 019 supplements that
>    decision, does NOT supersede it — the class hierarchy remains
>    authoritative for built-in tabs; the filter is an ADDITIONAL surface
>    for third parties. Update the memory decision to mark it as
>    "supplemented by Feature 019, not superseded".
> 3. `docs/planings-tasks/017-per-server-ability-selection.md` — established
>    the `safeApplyFilters` JS pattern (log-but-do-not-white-screen on
>    companion-plugin throws). Feature 019's `FilteredServerTab::render_body()`
>    is the PHP analogue for the same pattern.
> 4. `vendor/acrossai-co/main-menu/src/TabbedPageRenderer.php` (lines 111–184
>    in the `0.0.13` release) — the vendor's canonical `resolve_tabs()`
>    normalization + dedup loop. Feature 019's normalization MUST mirror this
>    algorithm so third-party authors have API parity between the vendor's
>    Settings tab filter and this plugin's per-server tab filter.
>
> Every decision — whether to allow third-party slugs to clobber built-ins,
> whether to allow priority overrides on built-ins from third parties,
> whether the render callback receives more than `$server` — must be
> justified against the above. Default: **first-registration wins,
> built-ins are seeded first, third parties can add/remove/reorder but not
> clobber a built-in's identity.**
>
> **Public API surfaces preserved (grep-gate before + after — no surviving
> consumer permitted to change):**
>
> - `\AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\Registry::instance()`
> - `\AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\Registry::all_tabs()` —
>   still returns the ten built-in instances in the same order.
> - `\AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\Registry::visible_tabs(
>   array $server ): AbstractServerTab[]` — same signature; return type
>   unchanged; behaviour now includes third-party contributions.
> - `\AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\Registry::render(
>   string $tab_slug, array $server ): void` — same signature; dispatches
>   built-ins AND third-party tabs.
> - `\AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\AbstractServerTab` —
>   `slug()`, `label()`, `visible_for()`, `render()` unchanged; NEW
>   `priority(): int` method with default 100.
> - `\AcrossAI_MCP_Manager\Admin\Partials\SettingsRenderer::render_tab_nav(
>   array $tabs, string $current, int $server_id )` — signature unchanged;
>   caller in `Settings::render_edit_page()` unchanged.
>
> **Runtime contract with third-party callers:**
>
> - Filter fires exactly once per Edit MCP Server request, inside
>   `Registry::for_server( array $server )`.
> - Filter callbacks receive the built-in list already seeded (so a
>   callback can `array_filter()` on slug to remove a built-in, `[] +=`
>   to add, or reorder by mutating `priority`).
> - Third-party `render_callback` is invoked exactly once per matching
>   dispatch (i.e. when `Registry::render( $slug, $server )` resolves to
>   the third-party entry), with `$server` as the sole argument.
> - Third-party `visible_callback` (if set) is called BEFORE the callback
>   is dispatched, and its `false` return short-circuits render.
> - A capability check on `capability` (default `manage_options`) runs
>   BEFORE `visible_callback` — if the current user cannot manage_options,
>   the tab is not shown and its render callback is never invoked.
> - A `Throwable` from either callback is caught, logged to `error_log()`,
>   and rendered inline as an admin-notice — the page does not
>   white-screen.
>
> ---
>
> **TASK-1 — extend `AbstractServerTab`.** Add:
> ```php
> public function priority(): int {
>     return 100;
> }
> ```
> to `admin/Partials/ServerTabs/AbstractServerTab.php`. Non-abstract, default
> 100. Docblock notes that Registry sorts effective tabs by ascending
> priority and that third-party entries default to 100, matching the last
> built-in (`DangerZoneTab`). Built-in tabs override to their slot value.
>
> **TASK-2 — slot the ten built-ins.** In each of `OverviewTab`, `NpmTab`,
> `ClientsTab`, `WpCliTab`, `ToolsTab`, `AbilitiesTab`, `AccessControlTab`,
> `McpTrackerTab`, `UpdateServerTab`, `DangerZoneTab`, add a
> `public function priority(): int { return N; }` override with N =
> 10, 20, 30, 40, 50, 60, 70, 80, 90, 100 respectively. No other change
> to these files.
>
> **TASK-3 — introduce `FilteredServerTab`.** New file
> `admin/Partials/ServerTabs/FilteredServerTab.php`. Class shape:
> ```php
> final class FilteredServerTab extends AbstractServerTab {
>     public function __construct( private array $entry ) {}
>     public function slug(): string { return (string) $this->entry['slug']; }
>     public function label(): string { return (string) $this->entry['label']; }
>     public function priority(): int { return (int) $this->entry['priority']; }
>     public function visible_for( array $server ): bool { … capability + visible_callback … }
>     protected function render_body( array $server ): void { … try/catch \Throwable … }
> }
> ```
> The `render_body()` implementation MUST wrap the invocation of
> `$this->entry['render_callback']( $server )` in `try { … } catch (
> \Throwable $t ) { … }`, log via `error_log( sprintf( '[acrossai-mcp-manager]
> Feature 019 — third-party render_callback for tab "%s" threw: %s in %s:%d',
> $this->slug(), $t->getMessage(), $t->getFile(), $t->getLine() ) )` (or
> equivalent per the plugin's logging convention), and echo an inline
> `<div class="notice notice-error inline">` explaining the failure to the
> operator without exposing stack traces. The other tabs on the page
> continue to render — the exception does NOT propagate.
>
> **TASK-4 — refactor `Registry`.** Preserve
> `Registry::all_tabs(): AbstractServerTab[]` verbatim (returns the ten
> built-ins). Add:
>
> - `private function builtin_entries(): array` — converts each built-in
>   from the class list into the entry-array shape used by the filter
>   (marks them with an internal `_builtin => true` flag).
> - `public function for_server( array $server ): AbstractServerTab[]` —
>   fires the filter, normalizes the result, dedups, hydrates back to
>   `AbstractServerTab[]`, sorts by priority. This is the effective tab
>   list; both `visible_tabs()` and `render()` call it internally.
> - `private function normalize_entries( array $raw ): array` — mirrors
>   vendor `TabbedPageRenderer::resolve_tabs()` (lines 111–184 of
>   `vendor/acrossai-co/main-menu/src/TabbedPageRenderer.php` at 0.0.13):
>   `sanitize_key` on slug, first-registration-wins dedup, `_doing_it_wrong`
>   under `WP_DEBUG` for missing slug / missing render_callback / duplicate
>   slug on the third-party side.
> - `private function hydrate( array $entries ): AbstractServerTab[]` —
>   for each entry: if `_builtin === true`, look up the built-in
>   instance from the slug map (`OverviewTab`, `NpmTab`, …). Else,
>   `new FilteredServerTab( $entry )`. Returns the AbstractServerTab list
>   sorted by `->priority()` ascending, ties broken by insertion order.
>
> `visible_tabs()` becomes:
> ```php
> public function visible_tabs( array $server ): array {
>     return array_values(
>         array_filter(
>             $this->for_server( $server ),
>             static fn ( AbstractServerTab $t ): bool => $t->visible_for( $server )
>         )
>     );
> }
> ```
>
> `render()` becomes:
> ```php
> public function render( string $tab_slug, array $server ): void {
>     $tabs = $this->for_server( $server );
>     foreach ( $tabs as $tab ) {
>         if ( $tab->slug() === $tab_slug ) {
>             $tab->render( $server );
>             return;
>         }
>     }
>     if ( ! empty( $tabs ) ) {
>         $tabs[0]->render( $server );
>     }
> }
> ```
>
> **TASK-5 — add PHPUnit tests.** Extend
> `tests/phpunit/Admin/ServerTabs/RegistryTest.php` with the seven cases
> listed in the parent plan file (`sharded-sparking-dawn.md` §"Files to
> modify"). Add a new file `tests/phpunit/Admin/ServerTabs/FilteredServerTabTest.php`
> for adapter-level unit tests. All tests use PHPUnit `#[DataProvider]`
> attributes per BUGS.md B9.
>
> **TASK-6 — extension author doc.** Add `docs/extending-per-server-tabs.md`
> with the filter contract, a worked "add a Notes tab" example, a
> "remove the MCP Tracker tab" example, and the throw-safety guarantee.
>
> **TASK-7 — verify.** Run:
> - `composer phpcs` — zero errors on new/modified files.
> - `composer phpstan` — level 8, zero errors, no new baseline entries.
> - `composer test` — new tests green.
> - Grep audit — `grep -n "apply_filters( 'acrossai_mcp_manager_server_tabs'"
>   admin/Partials/ServerTabs/Registry.php` returns exactly one match.
> - Manual: install a scratch companion plugin that hooks the filter to
>   (a) add a "Notes" tab, (b) remove `mcp-tracker`; verify both in wp-admin.
>
> ---
>
> **CONSTRAINTS (violations = defect):**
>
> - MUST NOT change the slug, label, or `visible_for()` implementation of any
>   built-in tab class.
> - MUST NOT change the signatures of `Registry::all_tabs()`,
>   `Registry::visible_tabs()`, or `Registry::render()`.
> - MUST NOT change the URL scheme in `SettingsRenderer::render_tab_nav()` or
>   `AbstractServerTab::server_edit_url()` — third-party tabs MUST inherit
>   the same `page/action=edit/server=N/tab=SLUG` scheme.
> - MUST NOT allow a third-party entry to clobber a built-in tab's slug
>   (first-registration wins, built-ins seeded first).
> - MUST NOT propagate a `Throwable` from a third-party callback — catch,
>   log, render inline notice.
> - MUST NOT bump the plugin's own version, edit the changelog, or ship a
>   release. This is a new-feature commit that flows through the normal
>   PR-to-main flow, mirroring Features 016 / 017 / 018's history.
> - Post-implementation grep: `grep -n "acrossai_mcp_manager_server_tabs"
>   admin/Partials/ServerTabs/` returns exactly one call site
>   (the `apply_filters` inside `Registry::for_server()`) — surviving
>   duplicate fires elsewhere are a defect.
