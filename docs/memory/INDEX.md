# Memory Index

This is a compact routing map for durable memory. Keep it short. It points to source entries and helps agents decide what to read; it does not replace the source memory files.

## Active Decisions
| ID | Title | Scope | Tags | Status | Source |
|---|---|---|---|---|---|
| D1 | PLUGIN_NAME_SLUG uses literal string; get_plugin_name() returns property | Boot flow | constants, boot | Active | DECISIONS.md |
| D2 | Rewrite rules registered immediately at activation with placeholder vars | Activation | rewrite, routing | Active | DECISIONS.md |
| D3 | Compat.php placed in includes/ not includes/Utilities/ (boot-time shim) | Module structure | compat, placement | Active | DECISIONS.md |
| D4 | class_exists() guards in Activator are always silent no-op | Activation | db, class-loading | Active [scope-narrowed F011] | DECISIONS.md |
| D5 | PHPCS baseline exceptions in phpcs.xml.dist (filename, $_instance, docblocks, namespace Public, PSR12 header) | Plugin-wide | phpcs, baseline | Active | DECISIONS.md |
| D6 | Activator.php MUST use `use` imports (not bare/inline FQN) for all DB class references | Activation | namespace, fqn | Active | DECISIONS.md |
| D7 | Activator does NOT call insert_default_server() — Phase 4 MCPServerQuery::maybe_create_table() internal | Activation | db, seeding | Superseded (F011) | DECISIONS.md |
| D8 | AccessControl stub targets wpboilerplate/wpb-access-control ^1.0 vendor package FQN | Phase 7 prep | access-control, vendor | Active | DECISIONS.md |
| D9 | BerlinDB-style Query interface (Schema/Table/Row/Query) hand-rolled — no berlindb/core vendor dep | Database layer | berlindb, query, vendor | Superseded (F011) | DECISIONS.md |
| D10 | Minimal-port deferral pattern — partial port + reserved follow-up task when source class depends on un-ported siblings | Migration | port, deferral, scope | Active | DECISIONS.md |
| D11 | Phase X.0 absorption — when a phase's P0 prereq doesn't yet exist, absorb its setup as a sub-phase in the consuming phase, not a separate dedicated phase | Process | phase, prereq, p0-gate | Active | DECISIONS.md |
| D12 | Bulk task-status updates MUST be followed by a re-audit of environment-dependent gates | Process | tasks, completion, ci-gates | Active | DECISIONS.md |
| D13 | Constitution-level formalization vs. Accepted Deviation — escalate to `.specify/memory/constitution.md` when the deviation describes a generalizable pattern (≥2 features or forward-looking); reserve INDEX.md `DEV*` rows for one-off carve-outs | Process | constitution, deviation, governance, generalizable | Active | DECISIONS.md |
| D14 | Cross-phase state observation via public-static predicate on the owning module — consumer uses `use` import, never duplicates internal magic strings; matches A11 + B11 defensive-read families | Cross-feature interface design | cross-phase, predicate, static, A11, S9-adjacent | Active | DECISIONS.md |
| D15 | Shared package bootstrap in plugin entry file — scoped A1 deviation for vendor packages owning cross-plugin resources; gated by `did_action('<resource>_bootstrapped')` idempotency + `class_exists()` defense-in-depth; established by Features 038 + 010 across the AcrossAI codebase family | Cross-plugin coordination | a1-deviation, shared-package, bootstrap, plugins_loaded, generalizable | Active | DECISIONS.md |
| D16 | Template-method helpers pre-plan an optional override for the value most likely to vary — prevents raw-call bypasses that trip DRY grep gates (F013 nonce_field escape hatch) | Helper design | template-method, dry, override, grep-gate | Active (F013) | DECISIONS.md |
| D17 | A1 hook-registration by transitivity — Loader-wired bootstrap methods inherit A1 conformance for their inner add_shortcode/add_action/add_filter calls; codified by F013 ClientRendererController::register_shortcodes_and_actions | Hook wiring | a1, transitivity, bootstrap, shortcodes | Active (F013) | DECISIONS.md |
| DEC-BERLINDB-TABLE-REQUEST-BOOT | BerlinDB Table subclasses MUST be instantiated at request time via `Main::load_hooks()` — activation-time `Table::instance()` alone leaves BerlinDB's DB interface empty on subsequent requests, causing Query to fall back to `$table_alias` as FROM | BerlinDB, request lifecycle | berlindb, boot, request-lifecycle, main-php, generalizable | Active (F011) | DECISIONS.md |
| DEC-BERLINDB-SUBCLASS-NO-USE-COLLISION | Do NOT `use BerlinDB\Database\Kern\<X>` when the subclass name matches the parent's name — the `use` claims the same local symbol and produces "Cannot redeclare class" fatals. Drop the `use` and extend via leading-`\` FQN, or alias the import | BerlinDB, namespace | berlindb, namespace, class-collision, subclass-naming, workflow-template | Active (F011) | DECISIONS.md |
| DEC-VENDOR-SETTINGS-TAB-INTEGRATION | Canonical shape for adding a tab to `acrossai-co/main-menu`'s shared Settings page — filter `acrossai_settings_tabs`, `SettingsPage::tab_page_slug()` helper, SHARED `'acrossai-settings'` option group (NOT the per-tab page slug), sibling-style class member ordering, no `class_exists()` guard on the vendor call; accepted §IV DataForm carve-out | Cross-plugin vendor integration | vendor-integration, settings-api, main-menu, dataform-carveout, class-exists-omission | Active (F012) | DECISIONS.md |
| DEC-UNINSTALL-OPT-IN-GATE | `uninstall.php` MUST short-circuit at the top when `acrossai_mcp_uninstall_delete_data !== 1`; preserve-by-default satisfies WP.org guideline #5; every destructive SQL statement MUST live after the gate; default value MUST be `0` | Uninstall path | uninstall, safety-invariant, wp-org-guideline-5, opt-in, behavior-change | Active (F012) | DECISIONS.md |
| DEC-ADMIN-SURFACE-PRUNE-CLI-AUTH-LOG | Standalone admin submenus for read-only DB-inspection views SHOULD be pruned when a lighter path exists (WP-CLI, per-server tab); first A9 subtractive-edit precedent — submenu removal + `plugin_screen_ids()` entry removal MUST land in the same feature; narrows DEV1 (WP_List_Table exception) to shared pages + dashboard widgets, not dedicated submenus | Admin surface | admin-surface, pruning, a9-subtractive-precedent, dev1-scope-narrowing, list-table | Active (F012) | DECISIONS.md |
| DEC-SERVER-TAB-CLASS-HIERARCHY | Template-method + Registry pattern for multi-tab admin surfaces — AbstractServerTab base with abstract slug/label/render_body + shared HTML helpers, Registry singleton for dispatch, final concrete tab classes with opt-in visible_for override. Canonical for any admin surface with 3+ tabs on a per-record edit page | Admin partials, multi-tab pages | template-method, registry, singleton, admin-partials, dry | Active (F013) | DECISIONS.md |
| DEC-CLIENT-RENDERER-PUBLIC-API | Public Renderer layer under public/Renderers/ for cross-context MCP client-config UI reuse. 4 sanctioned entry points: static render(), acrossai_mcp_render_client_block action hook, acrossai_mcp_client_block_context filter, 3 shortcodes. Plus acrossai_mcp_client_classes filter for MCPClientsBlock sub-nav extension. Security invariants: cap check via context.cap (never hardcoded manage_options), App Password locked to get_current_user_id() at both UI + REST layers, cross-context nonce action binding (server_id + context slug), F012 toggle enforcement inside Renderer. `@experimental` until 1.0.0 tag. Accepted §IV DataForm carve-out (JSON display + WordPress-core App Password creation, not data-entry form) | Public API, cross-plugin integration | public-api, renderer, cross-context, experimental, shortcode, security-critical | Active (F013) | DECISIONS.md |

## Architecture Constraints
| ID | Constraint | Scope | Tags | Source |
|---|---|---|---|---|
| A1 | All hook registration lives exclusively in `includes/Main.php` via `define_admin_hooks()` / `define_public_hooks()` | Plugin-wide | boot, hooks | ARCHITECTURE.md |
| A2 | Every feature class uses the singleton `instance()` pattern | Plugin-wide | singleton, DI | ARCHITECTURE.md |
| A3 | All admin UI classes live in `admin/Partials/` with namespace `AcrossAI_MCP_Manager\Admin\Partials` | Admin | namespace, structure | ARCHITECTURE.md |
| A4 | All new data forms use `DataForm` from `@wordpress/dataviews`; all new lists use `DataViews` | Admin UI | UI, components | ARCHITECTURE.md |
| A5 | MCP server listing MUST use `wpboilerplate/wpb-mcp-servers-list` package, not direct adapter calls | MCP module | integration | ARCHITECTURE.md |
| A6 | Any class in namespace `AcrossAI_MCP_Manager\\Includes` MUST use `use` imports or FQN with leading `\\` when referencing sub-namespace classes — bare relative names silently fail | Plugin-wide | namespace, fqn | ARCHITECTURE.md |
| A7 | All 6 plugin constants defined exclusively in Main::define_constants() via private define() guard — zero define() calls elsewhere | Plugin-wide | constants, boot | ARCHITECTURE.md |
| A8 | Access control wiring (Phase 7) MUST use \WPBoilerplate\AccessControl\AccessControlManager vendor package | Phase 7 | access-control, vendor | ARCHITECTURE.md |
| A9 | Shared admin constants (read by ≥2 modules) live in includes/Utilities/ as a final class, NOT on sibling feature classes | Plugin-wide | constants, coupling, utilities | ARCHITECTURE.md |
| A10 | WP_List_Table subclasses are exempted from the singleton-only rule — public ctor required by parent; instantiated per-render; never Loader-wired | Admin | list-table, singleton, exception | ARCHITECTURE.md |
| A11 | Pure service classes (stateless value producers, e.g. includes/MCPClients/) exempted from singleton rule — no instance state, no ctor args, no hook registration | Plugin-wide | singleton, service-class, exception, pure | ARCHITECTURE.md |
| A12 | Pure-PHP modules claiming WP-independence MUST have a tests/bootstrap.php that loads ONLY composer autoload — the test harness is the proof of the architectural claim | Plugin-wide | test-harness, purity, bootstrap, wp-free | ARCHITECTURE.md |
| A13 | RFC-prescribed forms (e.g. OAuth consent) are exempted from A4 DataForm mandate — cite the RFC section in the rendering class docblock | Admin UI | data-form, rfc, oauth, exception | ARCHITECTURE.md |
| A14 | WP-CLI dispatch classes follow A11-style exemption — stateless, no singleton, instantiated via `new self()` inside `\WP_CLI::add_command()` | Plugin-wide | wp-cli, singleton, exception, pure | ARCHITECTURE.md |
| A15 | Database-namespace audit-recorder static helpers (e.g. `<Module>\Recorder`) follow A11/A14 family — stateless static methods wrap `Query::add_item` for audit writes inside `try/catch` | Database, audit | recorder, audit, singleton, exception, pure | ARCHITECTURE.md |

## Bug Patterns
| ID | Pattern | Affected Area | Tags | Source |
|---|---|---|---|---|
| B1 | Namespace relative-path bug: bare `Includes\\Database\\...` inside `AcrossAI_MCP_Manager\\Includes` → double-Includes FQN → class_exists always false | Activator, any Includes class | namespace, silent-failure | BUGS.md |
| B2 | define_constants() null-property: using `$this->property` before it is set in constructor → constant defined as empty string | Main.php boot | constants, null | BUGS.md |
| B3 | TODO stub FQN namespace drift: missing \Includes\ segment → fatal on uncomment | Main.php stubs | namespace, stub | BUGS.md |
| B4 | Unescaped dot in add_rewrite_rule() PCRE pattern: '^.well-known/' matches any char; must be '^\.well-known/' | Activator | rewrite, regex | BUGS.md |
| B5 | Public constructor on singleton allows duplicate instantiation → double hook registration | Any singleton class | singleton, hooks | BUGS.md |
| B6 | admin_url() without esc_url() → filterable value injected into HTML href → XSS | Admin Partials | xss, escaping | BUGS.md |
| B7 | Mass-assignment via forged POST keys to $wpdb->update/insert — Query writers MUST filter against Schema::columns() before persisting | Custom DB tables | mass-assignment, query | BUGS.md |
| B8 | "// esc_url'd above" comments don't enforce escaping — re-escape at output point even if redundant (esc_* is idempotent) | Admin Partials renders | xss, escaping, defense-in-depth | BUGS.md |
| B9 | PHPUnit 13+ silently ignores `@dataProvider` annotations — use `#[DataProvider]` PHP attribute instead. Same for `@depends`, `@group`, `@test` | PHPUnit tests | testing, phpunit, attributes | BUGS.md |
| B10 | Check-then-act on one-shot credentials under concurrency → use atomic single-statement CAS (`UPDATE ... WHERE id = :id AND completed_at IS NULL`), not `SELECT` + `UPDATE` | OAuth, custom DB writes | concurrency, race, atomic-cas, one-shot | BUGS.md |
| B11 | Transient-stored associative arrays MUST be defensively validated with `is_array() + isset() + is_numeric()` triple-check on read — defends against partial writes, type drift, transient corruption | Transient readers, OAuth, CLI auth | transient, defensive, validation, partial-write | BUGS.md |
| B12 | wp_enqueue_scripts doesn't fire when template_redirect exits before wp_head() — call the enqueue method explicitly from the render helper, not only via Loader hook | template_redirect handlers, standalone HTML pages | enqueue, template_redirect, wp_head, silent-failure | BUGS.md |
| B13 | wp_redirect filter MUST throw to intercept the trailing exit in tests — returning false cancels the header but the test runner still dies on exit | PHPUnit tests of state-mutation redirects | testing, wp_redirect, wp_safe_redirect, exit, filter | BUGS.md |
| B14 | register_activation_hook default priority 10 fatals before higher-priority-number guards can run — vendor autoload checks belong at priority 1 to wp_die() gracefully before the main activation callback runs | Plugin activation, vendor autoload | activation, register_activation_hook, priority, silent-failure | BUGS.md |
| B15 | Regex verification gates that pattern-match only the bare-name form silently pass while missing the leading-`\` FQN form (`extends \Foo`) and short-name aliased form (`new Query()` via `use ...\Query;`) — use ERE with `\\?` optional-backslash or run two grep passes | Verification gates, code review | grep, regex, verification, gate-hygiene, false-negative, refactor, sweep | BUGS.md |
| B16 | Mixed positional (`%s`) + numbered (`%1$s`/`%2$s`) placeholders in a single printf silently mislabel output — the numbered placeholders bind to args 1..N (the leading text args), not the URLs/labels appended after. Split into two calls, or convert everything to numbered form | Admin partials that emit translated HTML with wp_kses_post() | printf, format-string, placeholder, i18n, visual-qa, silent-failure, xss-adjacent | BUGS.md |

## Accepted Deviations
| ID | Deviation | Scope | Expiry/Review | Source |
|---|---|---|---|---|
| DEV1 | MCP Manager parent menu (`?page=acrossai_mcp_manager`) uses `WP_List_Table` instead of DataViews | Admin UI | Never expires — pre-approved exception | CONSTITUTION.md §IV |
| DEV2 | `includes/Compat.php` lives in `includes/` not `includes/Utilities/` — boot-time shim exception to Principle I | Boot flow | Review if Utilities/ autoload order changes | DECISIONS.md D3 |
| DEV3 | Bidirectional `FrontendAuth` ↔ `CliController` import accepted pending A9 promotion to `includes/Utilities/CliAuthRoutes.php` — `CliController::auth_start` reads `FrontendAuth::get_base_url`; `FrontendAuth::handle_*` reads `CliController::approve_auth_code` + `peek_pending_server` | Feature-007 cross-phase coupling | Resolve via tasks.md T044 in next hardening branch; constitution §I Modular Architecture (no new violations) | Feature-007 / 2026-06-30 architecture-review V2 |
| DEV4 | FR-029 shared parent menu bootstrap (`\AcrossAI_Main_Menu\SettingsPage`) lives in `acrossai-mcp-manager.php` plugin entry file on `plugins_loaded` priority 0 — scoped A1 exception for cross-plugin shared vendor-package resources; gated by `did_action('acrossai_main_menu_bootstrapped')` + `class_exists()` per D15. Companion FR-030 pre-activation vendor guard on `activate_<plugin>` priority 1. | Feature-010 cross-plugin coordination | Review only if `acrossai-co/main-menu` API changes shape OR if a third AcrossAI plugin creates a distinct shared-resource contract; scope permanent as long as the shared parent menu contract holds | Feature-010 / 2026-07-02 FR-031 + D15 |

## Security Constraints
| ID | Constraint | Scope | Tags | Source |
|---|---|---|---|---|
| S1 | All forms and AJAX endpoints MUST verify a nonce before processing data | Plugin-wide | nonce, security | CONSTITUTION.md §III |
| S2 | All REST routes MUST have an explicit `permission_callback` — `__return_true` only on public read routes | REST API | permissions | CONSTITUTION.md §III |
| S3 | OAuth tokens and Application Passwords MUST be stored hashed (SHA-256 min) — never plaintext | OAuth, Auth | secrets | CONSTITUTION.md §III |
| S4 | All DB queries MUST use `$wpdb->prepare()` | Database | sql-injection | CONSTITUTION.md §III |
| S5 | admin_url() MUST be wrapped with esc_url() before use in HTML — it is filterable via the admin_url hook | Admin UI | xss, escaping | PROJECT_CONTEXT.md |
| S6 | Singleton __construct() MUST be private — public constructor allows duplicate instantiation and double hook firing | Plugin-wide | singleton, security | PROJECT_CONTEXT.md |
| S7 | OAuth token endpoint is the documented exception to S2 — `__return_true` is allowed because RFC 6749 §2.3.1 specifies auth via POST body; exactly one match permitted across `includes/OAuth/` | REST API, OAuth | permission_callback, rfc-6749, exception | PROJECT_CONTEXT.md |
| S8 | Body-authenticated mutating REST routes broader than S7 — `__return_true` permitted on CLI device-code-grant flows when Content-Type allow-list rejects missing/unknown headers BEFORE field validation AND downstream credential is bound to consented resource scope | REST API, CLI auth | permission_callback, content-type, server-binding, exception | PROJECT_CONTEXT.md |
| S9 | Consent-surface displayed-state MUST be sourced from server-side authoritative store (transient/option/DB), not URL params — confused-deputy / UI-misrepresentation defense | Consent surfaces (CLI, OAuth, device-grant) | confused-deputy, ui-misrepresentation, consent, deep-link, deep-link-spoof | PROJECT_CONTEXT.md |

## Worklog Entries
| Date | Feature | Summary | Source |
|---|---|---|---|
| 2026-07-02 | F011 | BerlinDB-backed Table subclasses — phantom-version guard (`maybe_upgrade` override) prevents silent short-circuit when version option stamped but physical table missing | WORKLOG.md |
| 2026-07-03 | F012 | Vendor-owned shared Settings page — `register_setting()` option_group MUST be the shared `'acrossai-settings'` slug, not the per-tab page slug; wrong group makes Save silently no-op with no operator-visible error | WORKLOG.md |

## Security Reviews
| File | Phase | Date | Risk | Findings | Constraints |
|---|---|---|---|---|---|
| docs/security-reviews/2026-07-02-011-berlindb-migration-plan.md | plan | 2026-07-02 | LOW | C:0 H:0 M:0 L:3 | A02,A04,A05,A08,A09 |
| docs/security-reviews/2026-07-03-012-mcp-settings-tab-plan.md | plan | 2026-07-03 | LOW | C:0 H:0 M:0 L:3 | A02,A05,A08,A09 |
| docs/security-reviews/2026-07-03-013-per-server-tabs-refactor-plan.md | plan | 2026-07-03 | LOW | C:0 H:0 M:0 L:3 | A01,A03,A05,A09 |
