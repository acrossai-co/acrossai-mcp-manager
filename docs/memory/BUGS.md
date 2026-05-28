# Bug Patterns

## Template
### YYYY-MM-DD - Bug / Failure Pattern
**Status**
Active | Monitored | Retired

**Symptoms**
What was observed?

**Root Cause**
What actually caused it?

**Future mistake prevented**
What change pattern should future work avoid?

**Evidence**
Failing test, production incident, review finding, or verified fix.

**Prevention / Detection**
How should future work avoid it and how can we catch it sooner?

**Where to look next**
Files, modules, logs, or checks maintainers should inspect.

---

### 2026-05-29 — Namespace Resolution Double-Includes in Activator.php

**Status**
Active

**Symptoms**
`class_exists( Includes\Database\MCPServer\Query::class )` inside
`Activator.php` always returns `false`. DB tables are never created at
activation. Activation completes silently with no error.

**Root Cause**
`Activator.php` is in namespace `AcrossAI_MCP_Manager\Includes`. PHP resolves
bare names relative to the current namespace. Writing `Includes\Database\MCPServer\Query`
inside that file produces the FQN `AcrossAI_MCP_Manager\Includes\Includes\Database\MCPServer\Query`
— a double-`Includes` path that resolves to nothing.

**Future mistake prevented**
Any file in `AcrossAI_MCP_Manager\Includes` that references a sub-namespace
class with a bare relative path (starting with `Includes\`) will silently fail.
This is especially dangerous in `class_exists()` checks, which return false
without throwing.

**Evidence**
Caught during `/speckit.plan` Phase 0 research (research.md §5).
Would have caused silent activation failures if deployed.

**Prevention / Detection**
ALWAYS use one of these forms inside any `AcrossAI_MCP_Manager\Includes` file:
- `use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;`
  then `class_exists( MCPServerQuery::class )`
- Or: `class_exists( \AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query::class )`
Run `vendor/bin/phpstan --level=8` — it catches unresolved class references.

**Where to look next**
`includes/Activator.php`, `includes/Main.php`, any file inside
`AcrossAI_MCP_Manager\Includes` that references sibling sub-namespaces.

---

### 2026-05-29 — Uninitialised $this->plugin_name in define_constants()

**Status**
Active (fix applied in Feature 001; pattern to avoid in future)

**Symptoms**
`ACROSSAI_MCP_MANAGER_PLUGIN_NAME_SLUG` is defined as empty string / null.
All callers that reference the slug constant get an empty value.

**Root Cause**
`define_constants()` was called BEFORE `$this->plugin_name = 'acrossai-mcp-manager'`
in the constructor. `$this->plugin_name` is null at that point. The `define()`
guard accepted null silently.

**Future mistake prevented**
Never use `$this->property` as the value argument to `$this->define()` in
`define_constants()`. The properties are set AFTER this method returns.

**Evidence**
Found in existing `includes/Main.php`. Fixed in Feature 001 spec (FR-003).

**Prevention / Detection**
Code review: verify `define_constants()` uses only literals and
`ACROSSAI_MCP_MANAGER_PLUGIN_FILE` (defined at file scope before Main::instance()).

**Where to look next**
`includes/Main.php::define_constants()`

---

### 2026-05-29 — Namespace Drift in TODO Stub FQNs [Feature-001]

**Status**
Active

**Symptoms**
`REST\CliController` TODO stub in `includes/Main.php` used namespace `\AcrossAI_MCP_Manager\REST\CliController` — missing `\Includes\` segment. Would fatal on uncomment in Phase 5.

**Root Cause**
Stub FQN not verified against the PSR-4 map in ARCHITECTURE.md before committing. Plan.md also contained the wrong FQN.

**Future mistake prevented**
Always verify every TODO stub FQN against the PSR-4 directory layout before writing it. Wrong-namespace stubs silently compile but fatal at runtime.

**Prevention / Detection**
Cross-check stub FQNs against ARCHITECTURE.md directory layout. PHPStan level 8 catches unresolved class references when the class exists; stubs won't be caught until the class is created.

**Where to look next**
`includes/Main.php` — all TODO stub comments containing `\AcrossAI_MCP_Manager\...` FQNs.

---

### 2026-05-29 — Unescaped Dot in PCRE Rewrite Rules [Feature-001]

**Status**
Active

**Symptoms**
`add_rewrite_rule( '^.well-known/oauth-authorization-server/?$', ... )` matches any character in place of the leading dot — `axwell-known/...` would also match.

**Root Cause**
Inside a PHP single-quoted string passed to `add_rewrite_rule()`, `.` is a bare PCRE wildcard. Must be `\\.` (double-escaped: one `\` escapes the PHP string, leaving `\.` for PCRE).

**Future mistake prevented**
All literal dots in `add_rewrite_rule()` patterns must be `\\.` in single-quoted PHP strings, not `.`.

**Prevention / Detection**
Code review: grep for `add_rewrite_rule` and verify all literal `.` chars are `\\.`.

**Where to look next**
`includes/Activator.php` — all `add_rewrite_rule()` calls.

---

### 2026-05-29 — Public Constructor on Singleton Allows Double Hook Registration [Feature-001]

**Status**
Active

**Symptoms**
External code calls `new \AcrossAI_MCP_Manager\Includes\Main()` directly. All plugin hooks register twice. In Phase 7 this can cause double-fired access-control middleware.

**Root Cause**
`Includes\Main::__construct()` was `public` rather than `private`. The `final` class modifier prevents subclassing but not direct instantiation.

**Future mistake prevented**
Every singleton `__construct()` MUST be `private`. Constitution rule. A `final` class alone is not sufficient protection.

**Prevention / Detection**
PHPCS / code review: all classes with `static $_instance` must have `private function __construct()`.

**Where to look next**
Any new class added to `admin/`, `includes/`, or `public/` with `$_instance`.

---

### 2026-05-29 — Missing esc_url() on admin_url() Output [Feature-001]

**Status**
Active

**Symptoms**
`sprintf('<a href="%sadmin.php?page=%s">', admin_url(), ...)` — `admin_url()` is filterable via the `admin_url` hook. A hijacked filter can return `javascript:alert(1)//`, producing stored XSS in the WP Admin plugins list.

**Root Cause**
`admin_url()` treated as a safe value because it typically returns a URL. It is not safe — it passes through a WordPress filter.

**Future mistake prevented**
Always wrap `admin_url()`, `get_admin_url()`, and similar filter-backed URL functions with `esc_url()` before use in any HTML attribute.

**Prevention / Detection**
PHPCS WPCS escaping sniffs. Code review: search for `admin_url()` in HTML context without `esc_url()` wrapper.

**Where to look next**
`admin/Partials/Menu.php` and any new admin Partials class with `plugin_action_links`.
