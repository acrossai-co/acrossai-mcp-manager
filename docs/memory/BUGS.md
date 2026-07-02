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

---

### 2026-06-25 — Check-Then-Act on One-Shot Credentials MUST Use Atomic CAS [Feature-005]

**Status**
Active

**Symptoms**
Two concurrent token-redemption requests with the same auth code both succeed: both issue access tokens, defeating the anti-replay guarantee. The "code already redeemed" check from the second request reads stale data because the first request hasn't yet flipped the `completed_at` flag.

**Root Cause**
The original FR-013 wrote `SELECT … WHERE redeemed_at IS NULL` followed by `UPDATE … SET redeemed_at = NOW()`. Under concurrent requests both SELECTs return NULL at T0 (predicate evaluates BEFORE either UPDATE), then both UPDATEs flip the flag at T1 — both pass their internal "not redeemed yet" check, both issue tokens. The same SELECT-then-UPDATE race exists in ANY DB-backed one-shot credential (auth codes, magic links, password-reset tokens, single-use coupons) and is silently exploitable under concurrent load.

**Fix Pattern (B10)**
The redemption step MUST be a single SQL statement of the form:

```sql
UPDATE <table>
SET completed_at = NOW()
WHERE id = :id AND completed_at IS NULL
```

Then inspect `$wpdb->rows_affected`:
- `1` → THIS request won the CAS, proceed with the privileged side effect
- `0` → another request already redeemed; fall through to the REPLAY branch (revoke any tokens issued by the sibling winner; return error; audit the replay attempt)

A `SELECT … WHERE completed_at IS NULL; if (! null) { UPDATE … }` pattern is NEVER acceptable for one-shot credentials, regardless of how short the window between SELECT and UPDATE is.

**Prevention / Detection**
- Code review gate: every new one-shot-credential redemption MUST include a concurrent-redeem PHPUnit test that runs the redemption N times against the SAME credential and asserts exactly ONE returns success (Phase 5 T054 `ConcurrentRedeemRaceTest` is the canonical shape).
- Audit gate: ANY token issued by the winner of an inverted CAS (the rare case where the loser's request issues a duplicate via a subsequent step) MUST be revoked in the replay branch.
- Grep gate: search for `SELECT.*WHERE.*completed_at IS NULL` followed by `UPDATE` — that's the regression pattern.

**Where to look next**
`includes/Database/CliAuthLog/Query.php::redeem_atomic` — the canonical CAS implementation. `includes/OAuth/Storage.php::redeem_authorization_code_cas` + `revoke_all_tokens_for_code` — the orchestration. `tests/phpunit/OAuth/ConcurrentRedeemRaceTest.php` — the load-bearing race test. `specs/005-oauth-connectors/spec.md` Q4 (SEC-001 amendment 2026-06-21) for the threat model.

---

### 2026-06-25 — Transient-Stored Associative Arrays Need Defensive Triple-Check on Read [Feature-006]

**Status**
Active

**Symptoms**
A read of a WordPress transient that's expected to hold an associative array (e.g. `array{user_id: int, server_id: string}`) returns the wrong shape and the calling code silently misbehaves — most often by reading `null` from a missing key, then comparing it to a real value with `===` (which returns false silently). Common failure modes:

- Object cache eviction during a partial write leaves the key set to `false` or to a different value-type than expected.
- A bug elsewhere in the code writes a bare `int` to the same key (e.g., during a refactor that changed the payload shape — this WAS the bug Q4 fixed).
- A transient TTL expires between two reads in the same request lifecycle.

**Root Cause**
PHP's `get_transient()` returns `false` on miss but ALSO returns `false` if the stored value is literally `false`. Combined with `isset()`'s lax "is the key set?" semantics (which returns `true` for an empty string `''`), naive single-line checks like `if ( false === $payload || ! is_numeric( $payload ) )` silently accept malformed data.

**Bug Pattern (B11)**
When reading a transient whose value is expected to be an associative array, use this triple-check pattern verbatim:

```php
$payload = get_transient( self::SOME_PREFIX . $key );
if ( ! is_array( $payload )                                            // catches false, scalars, objects
     || ! isset( $payload['expected_key_a'], $payload['expected_key_b'] )  // both keys present
     || ! is_numeric( $payload['expected_key_a'] )                     // value-type check for known-typed fields
) {
    return new \WP_Error( 'rest_unauthorized', '...', array( 'status' => 401 ) );
    // OR: return false from a static helper; OR: 404 from a polling endpoint
}
```

For transients with `array{key: int}` shape, use `is_numeric()` on the int field (catches strings that LOOK like ints AND real ints — WP transient storage strips int type on the wp_options fallback path). For `array{key: string}` fields, no additional check needed beyond `isset()`.

**Prevention**
- Code review gate: every `get_transient()` call that's expected to return an array MUST be followed by the triple-check.
- Static analysis: PHPStan L8 catches some shape mismatches but NOT runtime transient corruption — the triple-check is the runtime guard.
- Phase 5's `BearerAuth::resolve_bearer_token` (`includes/OAuth/BearerAuth.php`) reads a bare int value and uses a 2-line guard (`false === $user_id || ! is_numeric($user_id)`) — that's CORRECT for the bare-int shape. Phase 6's `verify_session_token` reads an array and uses the triple-check — that's the array-shape equivalent.

**Where to look next**
- Canonical implementation: `includes/REST/CliController.php::verify_session_token` (Phase 6) and `::handle_auth_status`.
- Counter-example (correct for the bare-int shape): `includes/OAuth/BearerAuth.php::resolve_bearer_token`.
- The Phase 6 Q4 clarification (`specs/006-rest-cli-auth/spec.md` §Clarifications) drove the array-shape adoption; prior-art bare-int reads in Phase 5 are fine because their payloads are bare ints, not arrays.

### 2026-06-30 — wp_enqueue_scripts Does Not Fire When template_redirect Exits Before wp_head() [Feature-007]

**Status**
Active — known failure mode for standalone HTML rendering via `template_redirect`

**Why this is durable**
WordPress fires the `wp_enqueue_scripts` action from inside `wp_head()`. Any handler hooked there only runs if the page goes through the theme rendering chain. When a plugin handles `template_redirect`, emits its own HTML, and `exit`s — the standard pattern for browser-mediated consent surfaces, well-known endpoints, JSON-LD pages, and custom Pretty URLs — `wp_head()` is never called and the `wp_enqueue_scripts` action never fires. Hooks wired via the Loader to `wp_enqueue_scripts` silently never run on these requests. The asset registration does not happen, and any subsequent `wp_print_styles( $handle )` call prints nothing because the style was never registered. The page renders unstyled with no error indication.

**Finding**
Wiring `enqueue_assets` to `wp_enqueue_scripts` via the Loader is necessary but not sufficient for `template_redirect`-based standalone pages. The page renderer MUST also call the enqueue method explicitly before `wp_print_styles()`, in addition to the hook wiring. The hook wiring is kept for future code paths that DO go through `wp_head()`; the explicit call covers the exit-before-head path. `wp_enqueue_style` is idempotent — both invocations are safe.

**Prevention**
- For any class wired to `wp_enqueue_scripts` that ALSO renders via `template_redirect` + `exit`, call the enqueue method explicitly from the render helper (e.g. `$this->enqueue_assets()` at the top of `render_page_shell()`).
- Test the asset registration with a dedicated PHPUnit case that asserts `wp_style_is( $handle, 'enqueued' )` after calling the render helper, NOT after firing `do_action( 'wp_enqueue_scripts' )`. Firing the action would mask the gap because the test harness sees the hook fire, but production code paths don't.
- If the page later starts using `wp_head()` (e.g. a feature flag adds DataViews to the consent UI), the explicit call becomes a redundant no-op via idempotency — no breakage.

**Evidence**
- 2026-06-30 mid-implementation bug: `public/Partials/FrontendAuth.php` initially relied solely on the `wp_enqueue_scripts` hook wired in `Main::define_public_hooks()`. The consent page rendered without CSS because the action never fired on the `template_redirect` exit path.
- Fix: `public/Partials/FrontendAuth.php` `render_page_shell()` adds `$this->enqueue_assets();` before `wp_print_styles( 'acrossai-mcp-frontend' )`.
- Test coverage: `tests/phpunit/FrontendAuth/EnqueueAssetsTest.php` asserts state after calling the render helper directly, not after firing the hook.

**Where to look next**
`public/Partials/FrontendAuth.php` — the explicit `$this->enqueue_assets();` call inside `render_page_shell()` and the docblock comment above it. Any future standalone-HTML page plugin should grep `wp_print_styles` and verify a paired explicit `enqueue_assets()` call exists in the same render method.

### 2026-06-30 — wp_redirect Test Interception MUST Throw From Filter, Not Return False [Feature-007]

**Status**
Active — established WP-PHPUnit testing convention

**Why this is durable**
The standard production pattern for state-mutating GET endpoints is `wp_safe_redirect( $url ); exit;`. WP_UnitTestCase wraps `wp_die()` with a custom handler that throws `WPDieException` so the test runner can catch it — but it does NOT wrap `exit`. Returning false from the `wp_redirect` filter cancels the `header( 'Location: …' )` call (the filter's documented purpose) but does NOT prevent the surrounding code from reaching `exit;`. The test runner then terminates mid-test. This trap is subtle because the test sometimes appears to "work" — if PHPUnit happens to run the offending test last, the runner exit is invisible in the output.

**Finding**
To intercept `wp_redirect` / `wp_safe_redirect` calls in tests without losing the test runner, the filter MUST throw an exception. The exception propagates up through `wp_redirect()` to the calling code, which never reaches `exit`. The test catches the exception via `try { … } catch ( \RuntimeException $e ) { /* expected */ }`. The repo's existing convention (see `tests/phpunit/OAuth/ClaudeConnectorsDiscoveryTest.php` for the parallel `wp_die` handler pattern) uses `RuntimeException`.

**Prevention**
- Use this exact pattern for any redirect interception:
  ```php
  $redirect_target = null;
  add_filter(
      'wp_redirect',
      static function ( $location ) use ( &$redirect_target ) {
          $redirect_target = $location;
          throw new \RuntimeException( 'redirect_intercepted' );
      },
      10,
      1
  );
  ```
- Catch `\RuntimeException` (or `\Exception` if your test also catches `WPDieException` via the same try/catch).
- Reset `$redirect_target = null` between multiple calls in the same test, since the filter persists across calls.
- Add the filter BEFORE the first redirect-emitting call in the test, not after — order matters.

**Evidence**
- 2026-06-30 mid-implementation bug: `HandleApproveTest` + `MaybeRenderPageTest` initially used `return false` and the test runner died on the first `wp_safe_redirect` path.
- Fix: switched to `throw new \RuntimeException( 'redirect_intercepted' )` in `tests/phpunit/FrontendAuth/HandleApproveTest.php` and `tests/phpunit/FrontendAuth/MaybeRenderPageTest.php`.
- Repo precedent: `tests/phpunit/OAuth/ClaudeConnectorsDiscoveryTest.php` uses the same throw-from-handler pattern for `wp_die` interception (line ~49 of that file).

**Where to look next**
`tests/phpunit/FrontendAuth/HandleApproveTest.php` private helper `run_approve()` — the catch pattern that handles BOTH `WPDieException` AND `RuntimeException` in a single test entry point is the canonical implementation.

---

### 2026-07-02 — register_activation_hook default priority 10 vs. priority-1 vendor guard [Feature-010]

**Status**
Active

**Why this is durable**
WordPress's `register_activation_hook( __FILE__, 'callback' )` internally registers on the action `activate_<plugin-basename>` at default priority 10. A separate `add_action( 'activate_' . plugin_basename( __FILE__ ), ..., N )` with lower priority number N runs BEFORE the default-priority-10 callback (WP hook ordering: lower number = earlier). If the priority-10 activation callback tries to load composer classes and `vendor/` is missing, it FATALS with an unhelpful PHP error before any later-priority guard can wp_die() with a friendly message. Users see a wall of PHP fatal instead of "run composer install".

**Pattern to apply**
For any activation-time vendor-autoload / vendor-class-existence check that must gracefully wp_die() with a user-friendly message, register the check at priority 1 on `activate_<plugin-basename>`, BEFORE the default-priority-10 `register_activation_hook()` callback runs:

```php
add_action(
    'activate_' . plugin_basename( __FILE__ ),
    static function () {
        if ( ! file_exists( __DIR__ . '/vendor/autoload_packages.php' ) ) {
            wp_die( esc_html__( 'Plugin cannot activate: run "composer install".', 'plugin-slug' ) );
        }
    },
    1  // Priority 1 — runs BEFORE the default-priority-10 activation callback
);
```

The two-hook pattern coexists cleanly:
- `register_activation_hook( __FILE__, 'activate_plugin' )` — priority 10, runs the actual activation work
- `add_action( 'activate_<basename>', fn() => guard, 1 )` — priority 1, runs FIRST, wp_die() early on missing prereqs

**Prevention rule**
For any activation-time prerequisite check that emits `wp_die()`, use `add_action('activate_' . plugin_basename(__FILE__), ..., 1)` — NEVER put the check inline in the register_activation_hook callback (which runs at default priority 10 and may fatal before your check).

**Evidence**
- `acrossai-mcp-manager.php:71–90` (Feature 010 / 2026-07-02 FR-030 implementation)
- `acrossai-abilities-manager/acrossai-abilities-manager.php:82–96` (Feature 038 reference implementation with `SEC-002` documentation)

**Where to look next**
For any future plugin activation prereq (PHP extension check, WP version check, MySQL feature check), apply the same priority-1 pattern. See D15 for the companion "shared package bootstrap in plugin entry file" pattern — B14 + D15 are the paired vendor-package resilience patterns.
