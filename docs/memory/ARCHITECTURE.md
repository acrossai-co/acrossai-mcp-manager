# Architecture — AcrossAI MCP Manager

Last reviewed: 2026-05-29 (Feature 001 index-project)

## System Overview

WordPress plugin with a PSR-4 WPBoilerplate layout. Provides MCP server management,
OAuth/Claude Connectors, CLI auth, frontend auth, and access control via a versioned
REST API. All wiring flows through a single `Main.php` entry point.

## Directory Layout

```
admin/
└── Partials/       # All admin-facing classes: menu, page renderers, asset enqueues

includes/
├── Main.php        # Single source of all hook registration
├── Loader.php      # Collects and fires add_action / add_filter calls
├── i18n.php        # Internationalization
├── Utilities/      # Shared utility functions, helpers, formatters
├── MCP/            # MCP server boot and registration
├── MCPClients/     # AI client configuration generators (one class per client)
├── OAuth/          # Claude Connectors OAuth 2.0 flow
└── REST/           # REST API controllers (CLI auth, access control)

public/
└── Partials/       # Frontend-facing classes (FrontendAuth virtual page)

src/
├── js/             # JavaScript source files (compiled by @wordpress/scripts)
└── scss/           # Stylesheet source files (compiled by @wordpress/scripts)

tests/
├── phpunit/        # PHP unit and integration tests
└── jest/           # JavaScript unit tests
```

## Boot Flow

```
acrossai-mcp-manager.php (plugin root)
  → acrossai_mcp_manager_run()
  → Main::instance()  [includes/Main.php]
      → define_constants()
      → load_composer_dependencies()   ← Jetpack autoloader only; NO hook calls
      → load_dependencies()            ← Loader singleton only; NO hook calls
      → set_locale()                   ← attaches I18n to Loader
      → load_hooks()
          → apply_filters('acrossai_mcp_manager_load', true)  ← kill switch
          → define_admin_hooks()       ← ALL admin + REST hook wiring via Loader
          → define_public_hooks()      ← ALL frontend hook wiring via Loader
  → add_action('plugins_loaded', [$plugin, 'run'])
  → Loader::run()  ← fires all registered hooks
```

## Singleton Pattern (Plugin-wide Convention)

Every feature class implements:

```php
protected static $_instance = null;

public static function instance(): self {
    if ( null === self::$_instance ) {
        self::$_instance = new self();
    }
    return self::$_instance;
}

private function __construct() { /* dependencies via OtherClass::instance() only */ }
```

`Main.php` always assigns to a named variable before wiring to the Loader:

```php
$mcp_controller = MCP\Controller::instance();
$this->loader->add_action( 'rest_api_init', $mcp_controller, 'register_servers' );
```

Passing `FeatureClass::instance()` inline as the second argument to `add_action` is prohibited.

## PHP Namespace Map

| Directory | Namespace |
|---|---|
| `includes/Main.php` | `AcrossAI_MCP_Manager\Includes` |
| `includes/MCP/` | `AcrossAI_MCP_Manager\Includes\MCP` |
| `includes/MCPClients/` | `AcrossAI_MCP_Manager\Includes\MCPClients` |
| `includes/OAuth/` | `AcrossAI_MCP_Manager\Includes\OAuth` |
| `includes/REST/` | `AcrossAI_MCP_Manager\Includes\REST` |
| `includes/Utilities/` | `AcrossAI_MCP_Manager\Includes\Utilities` |
| `admin/Partials/` | `AcrossAI_MCP_Manager\Admin\Partials` |
| `public/Partials/` | `AcrossAI_MCP_Manager\Public\Partials` |
| `includes/Database/MCPServer/` | `AcrossAI_MCP_Manager\Includes\Database\MCPServer` |
| `includes/Database/CliAuthLog/` | `AcrossAI_MCP_Manager\Includes\Database\CliAuthLog` |
| `includes/Database/ConnectorAuditLog/` | `AcrossAI_MCP_Manager\Includes\Database\ConnectorAuditLog` |

Always derive namespace from the full directory path — never invent short namespaces.

## Major Components

- **Main.php** — singleton, single source of all hook registration
- **MCP/Controller** — MCP server boot and registration via `wpb-mcp-servers-list` package
- **MCPClients/** — one class per AI client (e.g., `ClaudeCodeClient`)
- **OAuth/ClaudeConnectors** — OAuth 2.0 authorization code flow
- **REST/** — versioned REST API; split into per-domain sub-controllers at ~400 lines
- **admin/Partials/Settings** — admin menu, list table, settings pages
- **public/Partials/FrontendAuth** — virtual page for AI client auth

## Integrations

- **WPBoilerplate Access Control** — optional; wrapped in availability check
- **wpboilerplate/wpb-mcp-servers-list** — required Composer package for MCP server listing
- **Jetpack Autoloader** — wired via `vendor/autoload_packages.php`
- **@wordpress/scripts** — asset build pipeline
- **@wordpress/dataviews** — `DataViews` for lists, `DataForm` for forms (all new admin UIs)

## Boundaries

- `includes/` classes are context-neutral — MUST NOT contain admin-specific logic
- `admin/Partials/` owns all `add_menu_page()`, `wp_enqueue_*()`, and HTML rendering
- REST sub-controllers MUST NOT register WordPress hooks directly
- Feature modules MUST NOT depend on sibling modules directly — only on `includes/Utilities/`
- Optional integrations MUST be wrapped in availability checks; absent integrations must not fatal

## Risks / Complexity Hotspots

- OAuth token lifecycle: storage, refresh, revocation — hash-before-store requirement
- MCP server auto-discovery: MUST NOT block admin page rendering
- Jetpack autoloader versioning conflicts when the package list changes

## Keep Here

- Stable system boundaries and directory ownership
- Boot flow sequence (order of `load_*` calls is load-bearing)
- Namespace map (PSR-4 must mirror directory structure exactly)
- Integration constraints that affect multiple features

## Never Store Here

- Step-by-step implementation plans
- One-off feature details
- Stale diagrams that no longer reflect actual boundaries

Update the review date when boundaries, ownership, or integrations materially change.


## Module Inventory (as of Feature 001)

### Existing (boilerplate stubs — Phase 1)

| Class FQN | File | Status | Notes |
|---|---|---|---|
| `AcrossAI_MCP_Manager\Includes\Main` | `includes/Main.php` | Exists | Singleton; all hook registration |
| `AcrossAI_MCP_Manager\Includes\Loader` | `includes/Loader.php` | Exists | Singleton; collects add_action/add_filter calls |
| `AcrossAI_MCP_Manager\Includes\I18n` | `includes/I18n.php` | Exists | Wired via Loader on `init` |
| `AcrossAI_MCP_Manager\Includes\Activator` | `includes/Activator.php` | Exists | Static; called by activation hook |
| `AcrossAI_MCP_Manager\Includes\Deactivator` | `includes/Deactivator.php` | Exists | Static; called by deactivation hook |
| `AcrossAI_MCP_Manager\Admin\Main` | `admin/Main.php` | Exists | Enqueue admin assets |
| `AcrossAI_MCP_Manager\Admin\Partials\Menu` | `admin/Partials/Menu.php` | Exists | Admin menu + plugin action links |
| `AcrossAI_MCP_Manager\Public\Main` | `public/Main.php` | Exists | Enqueue public assets |

### Created in Feature 001

| Class FQN | File | Status |
|---|---|---|
| `AcrossAI_MCP_Manager\Includes\Compat` | `includes/Compat.php` | NEW — Phase 1 |

### Planned (TODO stubs in Main.php)

| Class FQN | Target File | Target Phase |
|---|---|---|
| `AcrossAI_MCP_Manager\Admin\Partials\Settings` | `admin/Partials/Settings.php` | Phase 3 |
| `AcrossAI_MCP_Manager\Admin\Partials\ApplicationPasswords` | `admin/Partials/ApplicationPasswords.php` | Phase N |
| `AcrossAI_MCP_Manager\Public\Partials\FrontendAuth` | `public/Partials/FrontendAuth.php` | Phase 3 |
| `AcrossAI_MCP_Manager\Includes\MCP\Controller` | `includes/MCP/Controller.php` | Phase 4 |
| `AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query` | `includes/Database/MCPServer/Query.php` | Phase 4 |
| `AcrossAI_MCP_Manager\Includes\Database\CliAuthLog\Query` | `includes/Database/CliAuthLog/Query.php` | Phase 4 |
| `AcrossAI_MCP_Manager\Includes\Database\ConnectorAuditLog\Query` | `includes/Database/ConnectorAuditLog/Query.php` | Phase 4 |
| `AcrossAI_MCP_Manager\Includes\REST\CliController` | `includes/REST/CliController.php` | Phase 5 |
| `AcrossAI_MCP_Manager\Includes\OAuth\ClaudeConnectors` | `includes/OAuth/ClaudeConnectors.php` | Phase 6 |

## Module Dependencies (Feature 001)

```
Main (includes/Main.php)
  ├─ depends on: Loader::instance()
  ├─ depends on: Admin\Main::instance()
  ├─ depends on: Admin\Partials\Menu::instance()
  ├─ depends on: Public\Main::instance()
  └─ [TODO stubs]: Settings, AppPasswords, FrontendAuth, MCP\Controller,
                   REST\CliController, OAuth\ClaudeConnectors, AccessControl

Activator (includes/Activator.php)
  ├─ optional: Database\MCPServer\Query (class_exists guard)
  ├─ optional: Database\CliAuthLog\Query (class_exists guard)
  └─ optional: Database\ConnectorAuditLog\Query (class_exists guard)

Compat (includes/Compat.php) — no dependencies
I18n (includes/I18n.php) — no dependencies
Loader (includes/Loader.php) — no dependencies
```

## Critical Namespace Rule (A6)

Any PHP file in namespace `AcrossAI_MCP_Manager\Includes` that references a
class in a sub-namespace MUST use `use` imports or a fully-qualified name
with a leading `\`. Example:

```php
// WRONG — resolves to AcrossAI_MCP_Manager\Includes\Includes\Database\...
class_exists( Includes\Database\MCPServer\Query::class )

// CORRECT
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
class_exists( MCPServerQuery::class )
```

See BUGS.md (B1) for the failure pattern this prevents.

## Constants Rule (A7) [Feature-001, 2026-05-29]

All 6 plugin constants (`PLUGIN_FILE`, `PLUGIN_DIR`, `PLUGIN_URL`, `PLUGIN_BASENAME`, `VERSION`, `PLUGIN_NAME_SLUG`) are defined exclusively inside `Main::define_constants()` via a private `define()` helper with an `if (!defined(…))` guard. Zero `define()` calls are permitted anywhere in `includes/`, `admin/`, or `public/`.

## Vendor Package Integration (A8) [Feature-001, 2026-05-29]

Access control wiring (Phase 7) MUST use `\WPBoilerplate\AccessControl\AccessControlManager` from `wpboilerplate/wpb-access-control ^1.0` Composer package — not an internal class.

## Shared Admin Constants Live in Includes\Utilities (A9) [Feature-002, 2026-06-17]

**Status**: Active

**Why durable**: When two or more feature classes need the same constant (page slug, option name, hook prefix, REST namespace), placing it on the most "obvious" owner class (e.g. `Menu::PAGE_SLUG`) immediately creates sibling-to-sibling coupling that violates Module Contract item 3 — and the violation propagates: every new consumer pulls in the sibling class header just to read a string.

**Architecture Rule**: If a constant is read by ≥2 classes in different feature modules, it MUST live in a `final class` under `includes/Utilities/*.php`. Feature classes consume it via `use AcrossAI_MCP_Manager\Includes\Utilities\Foo` + `Foo::CONST` — never via `SiblingFeature::CONST`. The utility class:
- Is `final`
- Has only `const` declarations + optional `public static` helpers
- Has a `private` constructor that is never invoked
- Holds no instance state (skip the singleton ceremony — no instance to share)

**Reference implementation**: `includes/Utilities/AdminPageSlugs.php` (Feature 002, RT-1).

**Tradeoffs**:
- Gained: zero sibling-to-sibling coupling; constants survive partial extractions and class renames cleanly; one consumer of the constant doesn't force-load the whole sibling class
- Reconsider: a constant scoped to exactly one class with no expectation of external readers can stay private to that class. Promote to Utilities only when a second reader appears.

## WP_List_Table Subclasses Are Exempted From the Singleton Rule (A10) [Feature-002, 2026-06-17]

**Status**: Active

**Why durable**: Constitution A2 says every feature class uses `protected static $_instance` + `private __construct`. WP_List_Table subclasses CAN'T — `\WP_List_Table::__construct()` is public and `parent::__construct(...)` must be called from a public child constructor. Without explicit exception documentation, future authors either (a) try to force singleton on a list table and break parent-constructor invariants, or (b) flag a constitutional violation when they see the public ctor.

**Architecture Rule**: Classes extending `\WP_List_Table` are exempted from the singleton-only rule because:
1. They MUST have a public constructor that calls `parent::__construct(...)` with table args
2. They are instantiated **per-render inside their controlling partial**, never wired into hooks via the Loader — so the B5 double-hook risk does not apply
3. They may legitimately take constructor parameters (e.g. `CliAuthLogListTable::__construct(int $server_id = 0)` for scope filtering)

The exception MUST be documented in the class file's PHPDoc with a pointer to this entry. Example (from `admin/Partials/MCPServerListTable.php`):
```php
/**
 * NOTE: List-table subclasses are excepted from the singleton-only rule
 * because (a) they extend \WP_List_Table which requires its own public
 * constructor + parent::__construct() call, (b) they are instantiated
 * per-render inside Settings, never wired into hooks via the Loader.
 * See docs/memory/ARCHITECTURE.md (A10).
 */
```

**Tradeoffs**:
- Gained: list tables work the WP-native way without contortions; admin pages render correctly with the canonical WP table UX
- Reconsider: never. This is a structural WP-core constraint, not a preference

## Pure Service Classes Are Exempted From the Singleton Rule (A11) [Feature-004, 2026-06-18]

**Status**: Active

**Why durable**: Constitution A2 mandates singleton + private `__construct` for every feature class. But classes that (a) hold no instance state, (b) take no constructor arguments, and (c) produce deterministic output from inputs alone — i.e. **pure value producers** — gain nothing from sharing a single instance. A singleton would add ceremony (`$_instance`, `instance()`, private ctor) for zero benefit AND create a "must this be unit-tested with the singleton state reset?" question that doesn't exist when each test instantiates fresh.

**Architecture Rule**: Classes in `includes/MCPClients/` (and equivalent stateless-value-producer modules under `includes/Utilities/` or similar) are exempted from the singleton-only rule because:

1. They hold no instance state — every method returns a deterministic function of its inputs alone
2. They take no constructor arguments — `new ClientName()` is sufficient at every use-site
3. They are instantiated per-use (typically per render or per request), never wired into hooks via the Loader — so the B5 double-hook risk does not apply
4. They are trivially unit-testable WITHOUT a state-reset dance (each test creates fresh instances)

The exception MUST be documented in the class file's PHPDoc with a pointer to this entry. Example (from `includes/MCPClients/AbstractMCPClient.php`):

```php
/**
 * Constitutional invariants (FR-008, FR-009):
 *   - No singleton pattern — instances are stateless and interchangeable.
 *
 * The singleton exemption is justified parallel to A10 (WP_List_Table
 * subclasses): different rationale (no instance state to share), same
 * outcome (not every class in the codebase is a singleton).
 * See docs/memory/ARCHITECTURE.md (A11).
 */
```

**How to tell whether a class qualifies for A11**:
- Has zero `private $_property` declarations beyond const? → A11 eligible
- Constructor takes arguments OR mutates state? → NOT A11 eligible; A2 applies
- Wired into hooks via the Loader? → NOT A11 eligible; B5 risk applies; A2 applies
- All methods return values that depend only on parameters? → A11 eligible

**Tradeoffs**:
- Gained: pure service classes are trivial to test, trivial to instantiate, immune to test-pollution bugs; module is easier to refactor because there's no shared instance to break
- Reconsider: never *unless* a "pure service class" grows instance state — at that point it ceases to qualify for A11, and A2's singleton rule applies again. If you find yourself adding `private $_x` to an A11 class, also add `$_instance` + `instance()` + private ctor in the same commit.

## Pure-PHP Modules MUST Have a WP-Free Test Bootstrap (A12) [Feature-004, 2026-06-18]

**Status**: Active

**Why durable**: When a module claims architectural purity (zero WordPress dependencies — no `$wpdb`, no `add_action`, no `get_option`, no `wp_*` calls), the test harness is the only thing that ACTUALLY proves the claim. A docstring asserting "this module is WP-free" is unverifiable; a test suite that loads ONLY the composer autoloader (no `wp-load.php`, no `wp-phpunit`) and successfully runs every test proves the module's claim mechanically.

**Architecture Rule**: Modules under `includes/` that make "pure PHP / WP-independent" architectural claims MUST:

1. Provide a `tests/bootstrap.php` that requires ONLY `vendor/autoload.php` (composer autoload). It MAY define `ABSPATH` as a constant so production files with `defined('ABSPATH') || exit;` guards still load — but that constant is the ONLY WordPress-y thing in the bootstrap.

2. Configure their PHPUnit testsuite in `phpunit.xml.dist` to use this WP-free bootstrap. Future test suites for WP-dependent modules (e.g. `Database/`, `Admin/Partials/`) will need a separate `tests/bootstrap-wp.php` that loads `wp-phpunit` — that's fine; the pure modules keep their own bootstrap.

3. Run their full test suite in the WP-free environment as a DoD gate (e.g. SC-003 in Feature 004). A test that needs `$wpdb` or any `wp_*` function will fail-fast; the failure IS the architectural-purity violation surfacing.

**Reference implementation**: Phase 4 (`tests/bootstrap.php`, `phpunit.xml.dist` testsuite "mcpclients") proves `includes/MCPClients/` is WP-free with 67 tests / 111 assertions all green.

**Tradeoffs**:
- Gained: architectural-purity claims become testable and self-enforcing; CI can mechanically catch regressions
- Reconsider: a module that grows a single legitimate WP dependency must either lose its purity claim (move to a separate testsuite with WP bootstrap) or refactor the dependency out. **DO NOT** add WP loading to the pure bootstrap to make one test green — that defeats the whole point of A12.

---

### 2026-06-25 — RFC-Prescribed Forms Exempted from DataForm/DataViews [Feature-005]

**Status**
Active

**Why this is durable**
A4 mandates DataForm for new admin forms. Phase 5 introduced the OAuth consent page — a plain `<form>` per RFC 6749 §4.1.1. The shape is RFC-prescribed (2 buttons, hidden inputs mirroring the authorize request), and DataForm doesn't model it cleanly. Without this carve-out, every future RFC-driven form (OpenID Connect logout, WebAuthn enrollment, etc.) would rediscover the same exemption argument.

**Architecture Constraint (A13)**
Forms whose shape is prescribed by an external RFC or W3C spec (not by project UX choice) are exempted from A4. The exemption applies when ALL three conditions hold:
1. The page is NOT a registered admin menu page (no DataViews routing applies)
2. The form shape is normatively specified by an external standard with a citation in the spec
3. The standard-prescribed shape would be diluted by wrapping it in DataForm

Each invocation MUST cite the standard section verbatim (e.g., "RFC 6749 §4.1.1") in the rendering class's docblock.

**Post-F016 (2026-07-07): NO active consumers.** The sole implementer (`includes/OAuth/ClaudeConnectors::render_consent_form`) was retired with the whole `includes/OAuth/` directory. Constraint REMAINS VALID for any future RFC-prescribed form (device-grant consent, PKCE authorize surface, WebAuthn enrollment, OpenID Connect logout) but currently has zero live consumers. Any future implementer MUST independently cite the exact RFC/W3C section per rule 2 above; this row is not a live-invocation-precedent to lean on.

**Tradeoffs**
Gained: standards-conformance without UI-framework ceremony; OAuth/WebAuthn flows match what users see on every other site.
Reconsider: if WP core ever ships a `DataForm` variant explicitly designed for OAuth/RFC-style consent pages, retire the carve-out.

**Future mistake prevented**
Don't wrap RFC-prescribed forms in DataForm "for consistency" — it breaks the canonical shape OAuth/OIDC clients expect, and review will reject the wrapping anyway.

**Evidence**
- `includes/OAuth/ClaudeConnectors.php::render_consent_form` (Phase 5, retired F016 2026-07-07)
- `specs/005-oauth-connectors/spec.md` §Admin UI Requirements (3 rationales)
- `specs/005-oauth-connectors/contracts/authorize-page.md` cites RFC 6749 §4.1.1

**Where to look next**
`docs/memory/ARCHITECTURE.md` A4 + A10 + A11 carve-out family; `contracts/authorize-page.md` for the canonical rendering contract.

---

### 2026-06-25 — WP-CLI Dispatch Classes Are A11-Style Exempt from Singleton Rule [Feature-005]

**Status**
Active

**Why this is durable**
A2 mandates singleton + private ctor for feature classes. Phase 5's `includes/OAuth/CliCommand.php` is a WP-CLI command dispatcher: stateless, registers no hooks, and is instantiated by `\WP_CLI::add_command( 'acrossai-mcp oauth', new self() )` per WP-CLI's documented pattern. Forcing it through the singleton ceremony adds zero value (WP-CLI calls each subcommand method directly) and introduces friction. This is the second stateless-utility exemption (after PKCE in the same phase), so the pattern deserves a named carve-out.

**Architecture Constraint (A14)**
A class is A14-exempt when ALL of these hold:
1. The class exists solely to surface methods to `\WP_CLI::add_command(...)`
2. The instance has no state (no instance properties; methods may call other singletons via `::instance()`)
3. The class registers no WordPress hooks (no `add_action`, no `add_filter`, no Loader wiring)
4. The class is instantiated exactly once, in a static `register()` method, via `new self()`
5. The `register()` method is itself gated by `defined( 'WP_CLI' ) && \WP_CLI`

Such classes MAY use a plain `public function` constructor (no `private` enforcement needed because they're never instantiated elsewhere). They MUST NOT be wired through `Main::define_admin_hooks()` / `define_public_hooks()`.

**Tradeoffs**
Gained: WP-CLI integration follows the framework's canonical pattern; no singleton ceremony to read past.
Reconsider: if WP-CLI ever exposes a singleton-friendly registration API, retire the carve-out.

**Future mistake prevented**
Don't wrap WP-CLI command classes in the singleton pattern "for consistency" — it adds friction and reviewers will reject the wrapping anyway.

**Evidence**
- `includes/OAuth/CliCommand.php` — the canonical implementation (T071 in tasks.md)
- `includes/Main.php::load_dependencies` (the WP-CLI-gated `register()` call)
- Pattern parallel: `includes/OAuth/PKCE.php` (A11 exemption — same shape, different motivation)

**Where to look next**
`docs/memory/INDEX.md` A11 (pure service classes) — the precedent carve-out from Feature-004; the two together form a named exemption family.

---

### 2026-06-25 — Database-Namespace Audit-Recorder Static Helpers Are A11-Style Exempt [Feature-006]

**Status**
Active

**Why this is durable**
A2 mandates singleton + private ctor for feature classes. Phase 6 introduced `Includes\Database\CliAuthLog\Recorder` — a stateless helper class with two static methods (`record_approved`, `record_success`) that wrap Phase 2's BerlinDB `Query::add_item()` for audit-row writes. The class has no instance state, no hooks, and is called only from feature classes that want a stable audit-write boundary. Forcing it through the singleton ceremony adds zero value. This is the THIRD A11/A14-family member (PKCE — Phase 5 pure math utility; CliCommand — Phase 5 WP-CLI dispatch; Recorder — Phase 6 audit helper), so the pattern deserves a named carve-out.

**Architecture Constraint (A15)**
A class is A15-exempt when ALL of these hold:
1. The class lives in `Includes\Database\<Module>\` (sibling to the BerlinDB Schema/Table/Row/Query files for the same `<Module>`).
2. The class exposes ONLY static methods (no public/private `__construct`, no `instance()`, no instance state).
3. Each static method wraps a single `( new Query() )->add_item([...])` call inside `try/catch (\Throwable)`.
4. Audit failures route to `error_log()` and the static method returns void — best-effort semantics, never throws or returns failure.
5. Feature classes (REST controllers, admin partials, hook callbacks) call these static methods INSTEAD of touching `Query::add_item` directly — `Query` is reserved for BerlinDB CRUD; `Recorder` is the audit-write boundary.

The class MAY be `final`. It MUST NOT be wired through `Main::define_*_hooks()`.

**Tradeoffs**
Gained: a stable audit-write boundary between feature classes and the BerlinDB Query layer; feature classes never accidentally bypass `try/catch` defensive shielding; future audit semantics (severity tagging, observability hooks) can land in `Recorder` without touching every call site.
Reconsider: if audit logic grows complex enough to need instance state (e.g. batching, in-memory buffer), promote to a singleton with `instance()` and revisit A15.

**Future mistake prevented**
Don't call `Query::add_item([...])` directly from a feature class for audit writes — it bypasses the `try/catch` defensive shielding and forces every caller to handle the silent-failure semantics. Use a `<Module>\Recorder` helper instead.

**Evidence**
- `includes/Database/CliAuthLog/Recorder.php` — the canonical implementation (T025 in Feature-006 tasks.md)
- 2 call sites: `includes/REST/CliController.php::approve_auth_code` (record_approved) + `handle_auth_exchange` step 8.7 (record_success)
- `tests/phpunit/RestCli/RecorderTest.php` — 3 test methods verifying both static methods + the unknown-server-slug graceful-degrade path
- Pattern parallel: `includes/OAuth/PKCE.php` (A11) and `includes/OAuth/CliCommand.php` (A14) — Recorder is the third member of the same family

**Where to look next**
`docs/memory/INDEX.md` A11 + A14 carve-out family; `specs/006-rest-cli-auth/spec.md` §Q1 Clarification for the boundary rationale.

---

### A16 — Every AcrossAI plugin consuming an `acrossai-co/*` vendor package MUST declare an explicit `repositories` VCS entry in `composer.json` [Feature-022, 2026-07-13]

**Status**: Active (Feature 022)
**Scope**: Every AcrossAI plugin whose `composer.json` requires ANY `acrossai-co/*` package (currently: mcp-manager, abilities-manager, model-manager, buddyboss-abilities, connectors, core-abilities, plus every future plugin in the ecosystem).
**Tags**: `composer, vendor, vcs-repository, packagist-lag, acrossai-co, generalizable`

**Constraint**

For every `acrossai-co/<package>` line in `composer.json`'s `require`, `composer.json` MUST also declare a corresponding VCS entry in `repositories`:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/acrossai-co/main-menu" },
    { "type": "vcs", "url": "https://github.com/acrossai-co/<other-package>" }
]
```

The VCS entry tells composer to resolve the package directly from GitHub (using git tags as the version source) instead of from Packagist.

**Why this is durable**

Packagist syncs new tags from linked GitHub repos on a webhook, but the sync is not instantaneous — F022 hit two consecutive races where composer refused to resolve just-pushed tags immediately after `git push origin <tag>`:

- 2026-07-12: `composer update acrossai-co/main-menu` failed with `"found ... 0.0.14"` immediately after tag `0.0.15` was pushed. Fixed by adding the VCS entry.
- 2026-07-13: same race repeated on tag `0.0.18` (fresh product-side change), self-resolved because the VCS entry was already in place from the earlier fix.

Without the VCS entry, every AcrossAI plugin bumping an `acrossai-co/*` dep has a non-deterministic window (seconds to minutes) where `composer update` returns stale results. The fix is one JSON line; the pain of forgetting it is real.

**Tradeoffs**
- Gained: deterministic resolution of `acrossai-co/*` versions immediately after tag push; no more "why is composer stuck on the old version" debugging.
- Made harder: `composer.json` grows one line per owned-vendor dep. Trivial cost.
- Reconsider: if AcrossAI publishes vendor packages through a private Packagist mirror with immediate-sync guarantees, this constraint becomes obsolete. Also skip for `acrossai-co/*` packages that are genuinely public on Packagist AND rarely tag (once/quarter or less).

**Future mistake prevented**

Don't just bump the `require` version and run `composer update` — check that a `repositories` VCS entry exists for that package first. If composer returns "not found" or "no matching version", the missing VCS entry is almost always the cause, not a broken tag.

**Evidence**
- F022 `composer.json` at `022-addons-page-registration` HEAD contains the entry `{ "type": "vcs", "url": "https://github.com/acrossai-co/main-menu" }` — added in commit `d8bb5d8` (T027).
- Session logs 2026-07-12 (initial race with 0.0.15) and 2026-07-13 (0.0.18 bump proceeded instantly because VCS entry was pre-existing).
- Pattern parallel: `wpboilerplate/wpb-access-control` uses the same VCS-entry approach in `composer.json` (that entry predates F022).

**Where to look next**

When onboarding a new AcrossAI plugin: audit its `composer.json` `repositories` array for a VCS entry per `acrossai-co/*` require. When adding a new `acrossai-co/*` dependency to an existing plugin: add BOTH the `require` line AND the `repositories` VCS entry in the same commit — never split them across two PRs.

---

### 2026-07-15 - A17 — Request-scoped WP REST context capture (rest_pre_dispatch + shutdown safety-net)

**Status**
Active — F026 v3 shipping pattern; applicable to any plugin callback that
needs REST-request-scoped context.

**Constraint**
When a plugin callback runs during REST-request dispatch but does NOT
receive the invoking `WP_REST_Request` (or its derived context) as an
argument — for example, an ability's `execute_callback` bound via WP core's
Abilities API — capture the needed context BEFORE dispatch using a
request-scoped singleton:

1. `add_filter( 'rest_pre_dispatch', 'capture_callback', 5, 3 )` —
   priority 5 to fire before any short-circuiting handlers at default 10.
   Callback signature: `( mixed $result, WP_REST_Server $server,
   WP_REST_Request $request )`. Match `$request->get_route()` against
   known route patterns, populate the singleton, return `$result` unchanged.
2. `add_filter( 'rest_post_dispatch', 'clear_callback', 999, 1 )` —
   normal-path cleanup.
3. `add_action( 'shutdown', 'clear_callback', 999 )` — **safety net**.
   Without this, long-lived PHP processes (Roadrunner, FrankenPHP, wp-env
   with keep-alive) leak the captured state across requests when a fatal
   error kills the request before `rest_post_dispatch` fires.

The singleton's `set()` method takes the context object; `clear()` takes
an optional passthrough (returned unchanged for use as a filter callback).

**Why this is durable**
The WordPress core abilities framework, and many similar dispatched-callback
patterns (custom REST endpoints handed off to service classes, cron
callbacks invoked from REST-triggered `wp_schedule_single_event`, etc.),
receive only `$input` — never the request context. The three-hook capture
pattern is the correct answer in every case. Missing the `shutdown` binding
is the number-one silent bug in ad-hoc versions of this pattern.

Reference implementation: `includes/Abilities/CurrentServerHolder.php`
(with a slug → integer PK cache invalidated at capture time to survive
mid-request server registrations).

**Where to look next**
- `includes/Abilities/CurrentServerHolder.php` (canonical singleton)
- `includes/Main.php:578-583` (wiring — three hooks at priorities 5/999/999)
- `DEC-F026-WP-REGISTER-ABILITY-ARGS-CALLBACK-SWAP` (D25 — the reason
  A17 exists in this codebase; the callback swap creates the need)
