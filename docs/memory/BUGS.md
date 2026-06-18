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

---

### 2026-06-17 — Mass-Assignment via Forged POST Keys to Custom-Table Writes [Feature-002]

**Status**
Active

**Symptoms**
A handler reads `$_POST` into a `$data` array and passes it to `$wpdb->update($table, $data, $where)` without filtering. A malicious admin (or a non-admin via a forged form that bypasses an upstream cap check) can include extra fields like `is_enabled=1`, `registered_from=plugin`, or future schema additions to write columns the form was never supposed to touch.

**Root Cause**
WP_DB `$wpdb->insert/update/delete` write every key in the `$data` array that corresponds to a column in the table — they do NOT filter against a schema whitelist. Trusting `$_POST` shape means trusting the attacker's shape.

**Future mistake prevented**
When writing to a custom DB table via a Query class, MUST iterate `Schema::columns()` (or an equivalent allow-list) and drop any unknown keys BEFORE the `$wpdb` call. Never pass raw `$data` from `$_POST` straight to `$wpdb->update/insert`.

**Prevention / Detection**
Canonical implementation pattern (see Feature 002):
```php
// In Query::update_item / add_item:
foreach ( $data as $col => $value ) {
    if ( ! $schema->has_column( (string) $col ) ) {
        continue; // silent drop
    }
    $update[ $col ] = ...;
}
```
Test: in a manual security test, submit a form with extra `<input name="is_enabled" value="1">` against the Claude Connector save handler. After submission, query the DB — the row's `is_enabled` MUST be unchanged.

**Where to look next**
`includes/Database/MCPServer/Query.php::add_item/update_item` for the canonical reference. Any future custom-table Query class MUST follow the same pattern.

---

### 2026-06-17 — "// esc_url'd above" Comment Pattern Is Fragile [Feature-002]

**Status**
Active

**Symptoms**
A form action attribute reads `<?php echo $post_url; // esc_url'd above ?>`. The escape was applied 10 lines earlier when `$post_url = esc_url( add_query_arg(...) )` was assigned. Currently safe, but a future refactor that renames `$post_url`, moves the assignment, swaps the assignment for an unescaped value, or copy-pastes the echo line into a new render method silently breaks the escape — XSS reintroduced with no audit trail. PHPCS WPCS escaping sniffs may or may not see across the variable assignment.

**Root Cause**
The comment is documentation, not enforcement. The reviewer / linter / future author has no signal at the **output point** that the value is pre-escaped. Defense in depth fails because there's only one defense.

**Future mistake prevented**
Even when a value was already escaped at assignment time, re-escape it at the output point. `esc_url()`, `esc_attr()`, `esc_html()` are all idempotent — calling them twice is cheap. The cost of being explicit is one function call; the cost of a silent regression is XSS.

**Prevention / Detection**
At output sites, always write `<?php echo esc_url( $post_url ); ?>` (or `esc_attr`, `esc_html`, etc.) — never bare `<?php echo $foo; ?>` with a "// already escaped" comment. PHPCS configurations should enable strict escaping rules at output sites regardless of upstream escaping. Pairs with B6 (admin_url XSS) for full coverage.

**Where to look next**
`admin/Partials/Settings.php` — every `<?php echo $post_url ?>` site has been hardened to `<?php echo esc_url( $post_url ); ?>` as of SEC-S2 (2026-06-17). Future render methods should follow that pattern.

---

### 2026-06-18 — PHPUnit 13+ `@dataProvider` Annotation Silently Fails [Feature-004]

**Status**
Active

**Symptoms**
A test method using `@dataProvider providerMethod` annotation throws `ArgumentCountError: Too few arguments to function ... 0 passed ... and exactly N expected` at runtime. The annotation is silently ignored by PHPUnit 10+ (and definitively in PHPUnit 13); the test runs once with no arguments instead of N times with provider data.

**Root Cause**
PHPUnit 10 deprecated annotation-style metadata in favor of native PHP attributes; PHPUnit 13 removed annotation support entirely (still parses the docblock but doesn't bind the provider). The migration is silent — no deprecation warning at test-load time.

**Future mistake prevented**
When writing PHPUnit tests in this repo (currently 13.2-dev per `composer.json`), use PHP attributes, NOT docblock annotations:

```php
// WRONG (PHPUnit 13 silently ignores)
/**
 * @dataProvider providerMethod
 */
public function testFoo( string $a, string $b ): void { ... }

// CORRECT
use PHPUnit\Framework\Attributes\DataProvider;
#[DataProvider('providerMethod')]
public function testFoo( string $a, string $b ): void { ... }
```

The same applies to `@depends`, `@group`, `@test`, etc. — every annotation has an attribute equivalent in `PHPUnit\Framework\Attributes\*`.

**Prevention / Detection**
PHPUnit's `--display-warnings` flag DOES surface this if enabled; otherwise the test silently fails with `ArgumentCountError`. Code review: search for `@dataProvider` (or any `@` test annotation) in new test files.

**Where to look next**
`tests/phpunit/MCPClients/AbstractMCPClientTest.php` for the canonical `#[DataProvider]` pattern + the `use PHPUnit\Framework\Attributes\DataProvider;` import.
