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

### 2026-07-02 — Regex verification gates that pattern-match only the bare-name form silently miss FQN and short-name aliased forms [Feature-011]

**Status**
Active

**Why this is durable**
Grep-based cross-file verification gates that pattern-match a target symbol using a **single surface form** silently produce **false negatives** against the other legal PHP spellings of the same symbol:

1. **Leading-`\` FQN form**: WPCS-compliant code often writes `class Foo extends \WP_List_Table` (leading backslash) rather than `extends WP_List_Table` — the bare-name grep `'extends WP_List_Table'` returns 0.
2. **Short-name aliased form**: files that add `use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query;` at the top can call `new Query()` — the qualified-name grep `'new [A-Za-z_]*Query'` misses these because there's no prefix at the call site.

In both cases the gate reports "0 matches — PASS" while the underlying invariant is actually intact — masking the bug where a future regression IS present.

**Pattern to apply**
For every grep-based verification gate on a target class/method/const, use one of:

**Option A — Single ERE that accepts both forms** (preferred when the pattern is short):
```
# Matches both `extends WP_List_Table` and `extends \WP_List_Table`
grep -cE 'extends\s+\\?WP_List_Table' <file>

# Matches both `new MCPServerQuery()` and `new Query()`
grep -rEn '\bnew\s+([A-Za-z_\\]+\\)?Query\s*\(\s*\)' <files>
```

**Option B — Two grep passes** (use when the ERE gets awkward or when the fixed-string form is clearer):
```
# Pass 1: qualified form
grep -rEn '\bnew [A-Za-z_]*(MCPServer|CliAuthLog)Query\s*\(' <files>
# Pass 2: short-name form (bound via `use ...\Query;`)
grep -rEn '\bnew\s+Query\s*\(\s*\)' <files>
# Gate = both greps must be green
```

**Prevention rule**
Any grep-based verification gate MUST account for at minimum:
(a) The bare-name form of the target symbol.
(b) The leading-`\` FQN form of the target symbol.
(c) The short-name aliased form (bound via a `use` import) when the target is a class name.

Reviewers writing verification gates in `tasks.md` DoDs (or in FR grep contracts) MUST test the gate against BOTH the intended-pass state AND the intended-fail state before shipping — a gate that returns 0 on both healthy and broken code is worse than no gate at all, because it lulls reviewers into believing the invariant is being enforced.

**Evidence**
- **Manifestation 1 — DEV1 non-widening gate false negative**: Feature 011 `tasks.md` T032 (pre-fix) used `grep -c 'extends WP_List_Table' admin/Partials/CliAuthLogListTable.php` which returned 0 because the file has `class CliAuthLogListTable extends \WP_List_Table` (leading `\`). Architecture-review V1 (2026-07-02) caught it; T032 fixed to use `grep -cE 'extends\s+\\?WP_List_Table'`.
- **Manifestation 2 — Pre-flight callers grep missed short-name form**: Feature 011 `spec.md` FR-020 (pre-remediation) used `grep -rEn 'new [A-Za-z_]*(MCPServer|CliAuthLog|OAuthToken|OAuthAudit)[A-Za-z_]*Query'` which missed 11 caller sites in `admin/Partials/Settings.php` (× 7), `admin/Partials/MCPServerListTable.php`, `admin/Partials/ApplicationPasswords.php`, and `includes/Database/CliAuthLog/Recorder.php` that use `use ...\Query;` at the top and call `new Query()` (bare short-name). Whole-plugin gate T037 (2026-07-02) surfaced the survivors post-workflow; FR-020 fixed to require a two-pass grep.

**Where to look next**
`tasks.md` T032 (post-V1-fix) shows the canonical `extends\s+\\?<Class>` idiom.
`spec.md` FR-020 (post-I1-fix) shows the two-pass idiom (qualified + short-name via `use`).
Any future FR that codifies a grep gate for a rename sweep or boundary preservation should reference this B15 entry in its DoD line.

### 2026-07-03 — Mixed positional/numbered printf placeholders in a single format string silently mislabel output [Feature-012]

**Status**
Active

**Why this is durable**
PHP's `printf`/`sprintf` accept BOTH positional (`%s`) and numbered (`%1$s`, `%2$s`, ...) placeholders in the same format string without an error. When you concatenate a positional-`%s` format-string with a numbered-`%1$s`/`%2$s`/`%3$s` i18n string (a common pattern when you want translator-friendly `wp_kses_post( __( 'metadata: <code>%1$s</code> ...' ) )` snippets inside a larger admin layout), the numbered placeholders bind to the FIRST N arguments — NOT to the arguments you appended AFTER the leading text arguments. Result: labels or URLs display against the wrong slot with no PHP warning, no PHPStan complaint, no PHPCS violation. The bug is invisible until visual QA catches the mislabeled output.

Feature 012 hit this in `SettingsMenu.php::render_claude_connectors_section_description()`: a single `printf` concatenated `'<p>%s</p>...<p><strong>%s</strong> %s</p>' . wp_kses_post( __( 'Authorization server metadata: <code>%1$s</code><br>Authorize URL: <code>%2$s</code><br>Token endpoint: <code>%3$s</code>' ) )` with 4 leading text-arg `%s` slots followed by 3 URL args. The rendered output showed `"Authorization server metadata: Optional direct Claude Connectors mode. Use this page only to turn the experimental feature on or off."` — because `%1$s` reached for the FIRST arg (the description label), not the AS metadata URL (which was arg 5).

**Pattern to apply**
When a `printf`/`sprintf` needs to compose a positional-`%s` outer layout with an i18n string that internally uses numbered `%1$s`/`%2$s` placeholders (usually because translators need the numbered form for word-order flexibility), do NOT concatenate the two format strings. Instead:

**Option A — Split into two calls** (preferred; each `printf` sees only ONE placeholder style):
```php
// Outer layout uses positional %s only:
printf(
    '<div class="notice notice-warning inline"><p><strong>%1$s</strong> %2$s</p><p>%3$s</p></div>',
    esc_html__( 'Do not cache the URLs.', 'text-domain' ),
    esc_html__( 'Long explanatory sentence.', 'text-domain' ),
    // Inner sprintf isolates the numbered placeholders inside their own i18n string:
    sprintf(
        /* translators: 1: AS metadata URL, 2: authorize URL, 3: token URL */
        wp_kses_post( __( 'AS metadata: <code>%1$s</code><br>Authorize: <code>%2$s</code><br>Token: <code>%3$s</code>', 'text-domain' ) ),
        esc_url( $as_metadata_url ),
        esc_url( $authorize_url ),
        esc_url( $token_url )
    )
);
```

Since the inner `sprintf` returns a fully-formatted string with all URLs already substituted, it can safely be passed to the outer `printf` as an ordinary `%s` argument (marked with `// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped` because the sniff can't statically see the `esc_url()`+`wp_kses_post()` chain — the escape is proven by construction).

**Option B — Convert everything to numbered form** (works only when the outer layout is ALSO a translated string; often it isn't):
```php
printf(
    /* translators: 1: label, 2: URL */
    wp_kses_post( __( '<strong>%1$s</strong>: <code>%2$s</code>', 'text-domain' ) ),
    esc_html( $label ),
    esc_url( $url )
);
```

**Prevention rule**
NEVER concatenate a format string containing `%s` with a format string containing `%1$s`/`%2$s`/`%3$s` inside a single `printf`/`sprintf` call. If the composition needs both styles (translated inner + literal outer, or vice versa), split into two calls where each format string uses ONE placeholder style consistently. Code review checkpoint: any `printf( '...' . wp_kses_post( __( '...%1$s...' ) ), ... )` line is a red flag.

**Evidence**
- **Manifestation**: Feature 012 `admin/Partials/SettingsMenu.php::render_claude_connectors_section_description()` (pre-fix) displayed "Authorization server metadata: Optional direct Claude Connectors mode..." because `%1$s` bound to the FIRST arg (description text) instead of the intended `esc_url( $as_metadata_url )` at arg-5. Reported by user during smoke QA (2026-07-03 session).
- **Fix commit**: Refactor to 3 separate `printf` calls; URL block built via nested `sprintf` with its own isolated numbered-placeholder i18n string.
- **Static analysis blindspot**: PHPStan L8 + PHPCS both passed on the buggy code — the mix is legal PHP; only runtime output revealed the label swap.

**Where to look next**
Any admin partial that emits `wp_kses_post( __( '...%1$s...%2$s...' ) )`-style translated snippets inside a larger `printf` call — verify each such call uses ONE placeholder style. Sibling `acrossai-abilities-manager` `SettingsMenu.php:212-220` shows the pattern working correctly because it uses positional `%s` throughout with no numbered-placeholder concatenation. Sibling wordpress-ai copy at `src/Admin/Settings.php:490-506` uses full `<?php ... ?>`-tag rendering which bypasses printf entirely — either idiom is safe; the mixed-mode idiom is the trap.

---

### 2026-07-04 - B17 — `rest_url()` returns URL WITH trailing slash; consumer concat produces `//`-double-slash 404s

**Status**
Active

**Why this is durable**
`rest_url()` returns the site's REST base URL WITH a trailing slash (e.g. `https://example.com/wp-json/`). Any consumer that builds sub-paths by string concat (`restApiRoot + '/wpb-ac/…'`) produces `//wpb-ac/…` which WordPress does not route → 404. Symptom is invisible in PHPStan/PHPCS/PHPUnit because the URL is only assembled at JS runtime by the downstream consumer.

**Evidence**
- **Manifestation**: F015 vendor `@wpb/access-control` React component received `restApiRoot = 'https://wordpress-7-0.local/wp-json/'` via `wp_localize_script` and concatenated `restApiRoot + '/wpb-ac/v1/mcp/providers'` — every apiFetch call resolved to `/wp-json//wpb-ac/…` and 404'd. Discovered when the Access Control tab picker was empty; DevTools revealed the double slash in Request URL.
- **Fix commit**: `admin/Main.php:195` — `'restApiRoot' => esc_url_raw( untrailingslashit( rest_url() ) )`. Same pattern applies to any `wp_localize_script` field whose consumer joins with a leading-slash sub-path.
- **Static analysis blindspot**: PHP-side lint is silent because the trailing-slash URL is well-formed. The bug only manifests in the JS consumer's URL builder.

**Where to look next**
Before passing `rest_url()` (or `home_url()`, `admin_url()`, `site_url()` — same trailing-slash convention) to any third-party JS bundle / config / template that will concatenate sub-paths, either strip the trailing slash — `esc_url_raw( untrailingslashit( rest_url() ) )` — or pass the fully-formed URL via `rest_url( 'sub-path' )` (WordPress joins correctly). Grep for the pattern: `grep -rn "restApiRoot\|rest_url()" admin/ includes/` and audit each site that hands the value to a downstream URL builder.

---

### 2026-07-04 - B18 — Strict-int comparison against MySQL TINYINT columns silently mislabels every row

**Status**
Active

**Why this is durable**
`$wpdb` returns MySQL TINYINT columns as string `"0"` / `"1"` — not int. Strict-equality checks like `1 === $row->is_enabled` are always false → boolean rendering silently defaults to the "0" branch on every row. On BerlinDB Row property reads, the declaration `public int $col = 0;` is a documentation hint, not runtime typing — the driver still returns strings. Bug is invisible to static analysis because the strict compare is valid PHP.

**Evidence**
- **Manifestation**: F015 session — `admin/Partials/MCPServerListTable::prepare_items()` used `'enabled' => 1 === $row->is_enabled` and every server (regardless of DB state) rendered as "Inactive" + "Enable" button. Same bug in `admin/Partials/Settings::toggle_server_status()`: `1 === $current_enabled` was always false, so the Active→Inactive transition silently no-op'd. The Overview tab used `! empty( $server['is_enabled'] )` and correctly showed "Active" — the mismatch between the two callers is what exposed the bug (same server row rendered as "Active" on the edit page and "Inactive" on the list).
- **Fix commit**: `MCPServerListTable.php:91` — `1 === $row->is_enabled` → `! empty( $row->is_enabled )`. `Settings.php:277` — `$current_enabled = (int) $rows[0]->is_enabled` before the strict compare.
- **Static analysis blindspot**: PHPStan L8 sees `$row->is_enabled` as `int` per the Row property doc-hint and considers `1 === int` valid. Runtime typing is where the bug lives.

**Where to look next**
When rendering a table column derived from a boolean-shaped int, prefer `(bool) $row->col` in the row-map or `! empty( $row->col )` for boolean semantics. When comparing strictly, cast: `1 === (int) $row->col`. Grep gate for new BerlinDB Row consumers: `grep -nE '(===|!==) *\$row->' admin/ includes/` to catch strict-compare-on-driver-string patterns. Applies to every plugin using `$wpdb` or BerlinDB — not F015-specific.

---

### 2026-07-04 - B19 — WP Application Password client-config generators MUST emit both `WP_API_USERNAME` and `WP_API_PASSWORD`

**Status**
Active

**Why this is durable**
WordPress Application Passwords authenticate via HTTP Basic (`Authorization: Basic base64(user:pass)`). A client config that ships only `WP_API_PASSWORD` (env var, CLI flag, JSON key) breaks auth silently — the consuming MCP/HTTP client can't build the Basic header without both. Symptom: user pastes the generated config, tool starts, every request 401s with no obvious reason (nothing in `wp-admin` says "your config is missing the username"). Bug is a "shipped without the peer field" pattern — trivial to write, hard to spot on inspection because each individual `env` block looks internally consistent.

**Evidence**
- **Manifestation**: F015 session — 7 MCP client classes (`VSCodeClient`, `ClaudeDesktopClient`, `CursorClient`, `CodexClient`, `CustomClient`, `GitHubCopilotClient`, `ClaudeCodeClient`) all shipped configs with `WP_API_PASSWORD` but no `WP_API_USERNAME`. Reference plugin `acrossai-mcp-manager` at `/Users/raftaar1191/local-sites/wordpress-ai/…/src/Admin/ApplicationPasswords.php:366-367` had both — the bug was a "port omitted a field" regression.
- **Fix commit**: Added `AbstractMCPClient::current_username()` helper returning `wp_get_current_user()->user_login`. Every concrete client's `get_config_snippet()` now emits `'WP_API_USERNAME' => $this->current_username()` between URL and PASSWORD. `ClaudeCodeClient` (CLI form) uses `escapeshellarg($username)` in the sprintf.
- **Static analysis blindspot**: PHPStan + PHPCS both green — each client class is self-consistent. Test coverage passed because tests asserted `env.WP_API_PASSWORD === '(placeholder)'` without inspecting siblings.

**Where to look next**
When a plugin generates client-facing WP-API auth configs, ship `WP_API_USERNAME` (from `wp_get_current_user()->user_login`) as a peer of `WP_API_PASSWORD`. Add a helper method on the abstract config generator (e.g. `AbstractMCPClient::current_username()`) so no subclass forgets it. Verify by grepping every concrete generator: `grep -L 'WP_API_USERNAME' includes/MCPClients/*.php` should return zero files. Applies to any plugin family generating MCP/HTTP client configs, wp-json REST curl examples, or WP-CLI login snippets that depend on Application Passwords.

---

### 2026-07-07 - B20 — Plaintext OAuth secret in `varchar(255)` column (S3 constitution violation)

**Status**
Active — retroactively closed by F016 for `claude_connector_client_secret`. Prevention pattern durable.

**Why this is durable**
Constitution §III S3 says "OAuth tokens and Application Passwords MUST be stored hashed (SHA-256 minimum) — never plaintext". A `char(64)` column paired with `hash('sha256', $secret)` in the write path enforces this at both the DDL layer (column can't hold anything but a fixed-width digest) and the code layer (the hash call is the only sane thing to write). A `varchar(255) default ''` column paired with `sanitize_text_field()` does NOT — it silently accepts and stores the plaintext secret. Static analysis (PHPCS/PHPStan) is blind to this because both the column definition and the sanitizer call are individually well-formed. Grep is the only reliable detection.

**Symptom**
A BerlinDB `Schema.php` column definition like:
```php
array( 'name' => 'foo_client_secret', 'type' => 'varchar', 'length' => '255', 'default' => '' ),
```
paired with an admin form handler that writes `sanitize_text_field($_POST['foo_client_secret'])` directly into that column via `$query->update_item()`. The plaintext secret is now stored on disk (`.ibd` tablespace), in the MySQL binary log, in every backup, and potentially in slow-query / general-query logs.

**Evidence**
- **Manifestation**: `admin/Partials/Settings.php::handle_claude_connector_update` (deleted in F016 2026-07-07) wrote `sanitize_text_field($_POST['claude_connector_client_secret'])` into `wp_acrossai_mcp_servers.claude_connector_client_secret varchar(255) default ''`. The column existed for ~5 months before the S3 violation was surfaced during F016 security review SEC-STAGED-001.
- **Retroactive fix**: F016 (a) deleted the write path (`handle_claude_connector_update` + `handle_actions()` allow-list entry); (b) published a manual retirement recipe including a pre-DROP `UPDATE wp_acrossai_mcp_servers SET claude_connector_client_secret = ''` step to force InnoDB tablespace overwrite before column drop; (c) dropped the three `claude_connector_*` columns via operator-run `ALTER TABLE ... DROP COLUMN` (per DEC-FRESH-INSTALL-ONLY-RETIREMENT / D21 pattern).
- **Static analysis blindspot**: PHPCS + PHPStan both green through the entire ~5-month window. Neither tool inspects Schema column type/length against write-path helpers.

**Where to look next**
Every future custom-DB table introducing a `_secret`, `_token`, `_password`, or `_key` column MUST use `char(64)` (SHA-256) or `char(*)` (larger digest, e.g. `char(128)` for SHA-512). `varchar` on a secret/token column is a review-time hard-fail. Reviewer grep-gate:
```
grep -rEn "'name' *=> *'[^']*_(secret|token|password|key)'" includes/Database/
```
Every hit MUST have `'type' => 'char'` with `length >= 64` on the next 1-3 lines. If `'type' => 'varchar'` or shorter length appears, either (a) reject the PR, or (b) confirm the column is intentionally storing a non-secret (e.g. a public identifier, an opaque request ID) and add an inline comment justifying it.

Retroactive-fix pattern (F016 canonical): (1) delete the write path (admin form handler + REST controller); (2) pre-DROP `UPDATE table SET secret_col = ''` to overwrite InnoDB pages; (3) `ALTER TABLE ... DROP COLUMN`; (4) update `Schema.php`, `Row.php`, `DefaultServerSeeder.php` to remove the field entirely. Applies to any custom-DB plugin storing OAuth secrets, API keys, or session tokens.

---

### 2026-07-07 - B21 — BerlinDB v3 recognized column flags do NOT include `date_updated`

**Status**
Active — surfaced during F017 implementation on PHP 8.2 (2026-07-07).

**Why this is durable**
BerlinDB v3's `Kern\Column` docblock enumerates ~20 recognized column flags. `created` (INSERT-time timestamp) and `modified` (UPDATE-time timestamp) are the two datetime flags — there is NO `date_updated` flag despite the intuitive name. Passing an unrecognized flag as a column-args key silently creates a dynamic property on `Column`, which trips PHP 8.2+'s "Creation of dynamic property Column::$X is deprecated" notice at every column boot. In `debug.log`, this looks like every request logs the same deprecation for every Schema definition using the wrong flag. In an admin-only path, it's an ugly noise wall in `wp-content/debug.log`; on a live install with `WP_DEBUG_DISPLAY = true`, the notice would surface at the top of every admin page.

**Symptom**
```
Deprecated: Creation of dynamic property BerlinDB\Database\Kern\Column::$date_updated is deprecated
in vendor/berlindb/core/src/Database/Traits/Base.php on line 183
```
Row inserts and updates still succeed (BerlinDB's `save_item()` handles the `created` timestamp; without a valid `modified` flag, the `updated_at` column never gets auto-stamped). The bug is silent at the DB layer but noisy at the PHP layer.

**Evidence**
- **Manifestation**: F017 `includes/Database/MCPServerAbility/Schema.php` shipped with `'date_updated' => true` on the `updated_at` column. On the developer's local install running PHP 8.2, every `Query::instance()->query(...)` (executed on every request from `Main::bootstrap_database_tables()` → `Table::instance()`) fired the deprecation.
- **Root cause**: I intuited the flag name from the memory `A11/A15` pattern documentation and BerlinDB's `date_query` flag, without checking the BerlinDB Column docblock. The docblock at `vendor/berlindb/core/src/Database/Kern/Column.php:38-56` is the authoritative list of recognized flags.
- **Fix**: Change `'date_updated' => true` → `'modified' => true`. No DDL change needed (the column type is already `datetime`); BerlinDB's next `maybe_upgrade()` diff-pass leaves the column shape untouched.

**Where to look next**
The BerlinDB v3 Column docblock at `vendor/berlindb/core/src/Database/Kern/Column.php:38-56` is the authoritative list of recognized flags. Recognized datetime flags: `created` (INSERT-time), `modified` (UPDATE-time), `date_query` (enables __between / __compare / __not_in variants). Recognized boolean flags include `unsigned`, `zerofill`, `binary`, `allow_null`, `primary`, `uuid`, `searchable`, `sortable`, `in`, `not_in`, `cache_key`, `transition`. Any Schema flag NOT on that list becomes a dynamic property on PHP 8.2+.

Reviewer grep-gate for every new Schema.php:
```
grep -rEn "'(date_updated|updated_date|modified_date|updated_time)'" includes/Database/
```
MUST return zero matches — these are all common misspellings of the `modified` flag.

Broader lesson: when authoring subclass configs against a vendor package's args array, read the vendor's docblock @param list end-to-end. Do NOT rely on flag names inferred from memory documentation or sibling code, especially when the vendor was recently upgraded (BerlinDB v3 renamed several v2 flags).

---

### 2026-07-08 - B22 — New `@wordpress/*` packages need runtime string store lookup, not build-time import

**Status**
Active — surfaced during F017 implementation on `@wordpress/abilities@0.16.0` (2026-07-08).

**Why this is durable**
`@wordpress/scripts` (v30.x) maintains an internal externals map that translates `import ... from '@wordpress/foo'` into the runtime handle `wp.foo` + the enqueue-side dep `wp-foo`. Packages not yet in that map get either bundled (silent bloat) or manifest-listed under a handle WordPress doesn't actually register (silent no-op). The failure is invisible — the bundle builds, the JS runs, the exported symbol imports OK, but its runtime side-effects (store registration) never fire.

**Symptom**
```js
import { store as fooStore } from '@wordpress/foo';
useSelect( ( select ) => select( fooStore ).getSomething() );  // returns undefined forever
```
The React tree hangs on a permanent loading state; no console error; the network tab shows no dependency handle loaded.

**Evidence**
F017 initial implementation at `src/js/abilities.js` imported `store as abilitiesStore` from `@wordpress/abilities`. Tab rendered `Loading abilities…` indefinitely. Rewrite to string-key runtime lookup + REST fallback resolved it: `wp.data.select( 'core/abilities' )` returns undefined at that moment, the fallback fetches `GET /servers/{id}/abilities?include_abilities=1`, and the tab populates from the server-shipped ability list.

**Where to look next**
The canonical shape lives in `src/js/abilities.js:52-100` — a `ABILITIES_STORE_KEY` constant, a `useSelect` that returns `null` when the store isn't registered, and a REST fallback state populated by the `?include_abilities=1` path. Add this pattern to every future `@wordpress/*` package the plugin adopts until the package lands in `@wordpress/scripts`' externals bundle. Reviewer grep-gate: `grep -rEn "import.*from '@wordpress/(?!scripts|env)" src/js/` — any hit is a candidate for the string-key rewrite.

---

### 2026-07-08 - B23 — Test-suffix method names on production-load-bearing helpers

**Status**
Active — surfaced during F017 staged security review (SEC-STAGED-001, 2026-07-08).

**Why this is durable**
A method named `_reset_cache_for_tests()`, `_for_testing()`, or `_test_only_reset()` signals "safe to remove, guard, or replace with a mock" to any maintainer reading the source. When production code silently depends on the method to enforce an invariant, removal produces no visible failure — the invariant just quietly stops holding. Common invariants at risk: per-request cache invalidation between two reads, singleton reset between requests, shared static state clearing between hook fires.

**Symptom**
```php
final class ExposureResolver {
    private static array $cache = array();
    public static function _reset_cache_for_tests(): void {  // <-- name lies
        self::$cache = array();
    }
}
// AbilitiesController::post_abilities():
$was = ExposureResolver::resolve( $server_id, $slug, $meta );
Query::instance()->upsert( $server_id, $slug, $is_exposed );
ExposureResolver::_reset_cache_for_tests();  // <-- production call site!
$now = ExposureResolver::resolve( $server_id, $slug, $meta );
if ( $was !== $now ) { do_action( 'exposure_changed', ... ); }
```
Maintainer sweeps `grep -rn "_for_tests" includes/` looking for cleanup targets, removes the method — `$was === $now` becomes always-true — `exposure_changed` action never fires again — silent regression on the audit contract.

**Evidence**
- `includes/Database/MCPServerAbility/ExposureResolver.php:75-77` — method definition.
- `includes/REST/AbilitiesController.php:~215, ~264` — production call sites.
- Staged security review 2026-07-08 SEC-STAGED-001 (MEDIUM) — full analysis.

**Where to look next**
Reviewer grep gate: `grep -rEn '_reset.*for_tests|_for_testing|_test_only' includes/ src/`. Each hit needs classification:
- Called ONLY from `tests/**` → legitimate, keep test-suffix name, no action.
- Called from production code (`includes/`, `admin/`, `public/`) → rename to production-shape (`clear_request_cache`, `reset_state`), OR redesign to eliminate the dependency (e.g., have `Query::upsert_and_get_effective()` return the new value directly, skipping the resolver's cache entirely).

Applies to any static / singleton state reset the plugin uses to enforce a cross-call invariant.

---

### B24 — Vendor accessor assumption via `instanceof` silently fails when vendor namespace differs

**Status**: Active (F020 — 2026-07-09)
**Scope**: Cross-package integration, especially `mcp-adapter` / any vendor object accessed via WordPress action/filter payload.
**Tags**: `vendor-accessor, method-exists, instanceof-antipattern, silent-failure, enforcement-gate, sec-020-007, generalizable`

**Why this is durable**

F020's first plan-remediation drafted `$server instanceof \WP\MCP\Server` and `$server->get_id()` for the runtime enforcement gate — plausible-looking pattern copied from casual documentation. The real vendor class is `\WP\MCP\Core\McpServer` (verified at `vendor/wordpress/mcp-adapter/includes/Core/McpServer.php:26`) with `get_server_id(): string` (returning a slug, not an int). As written, the `instanceof` check would have failed on every real request, `$server_id` stayed `0`, the fail-open branch triggered, and the enforcement gate became a **no-op**. Same effective outcome as the original SEC-020-001 gap the remediation was meant to close. Only caught because a second security review verified the contract text against the vendor's actual source.

**Pattern to prevent**

Use **duck-typed feature detection** for cross-package accessors:

```php
// WRONG — vendor namespace assumptions rot silently:
if ( $server instanceof \WP\MCP\Server ) {
    $id = (int) $server->get_id();
}

// RIGHT — feature-detected, forward-compatible with vendor refactors:
if ( ! is_object( $server ) || ! method_exists( $server, 'get_server_id' ) ) {
    return $args; // Fail-open.
}
$slug = (string) $server->get_server_id();
```

F015 (`AcrossAI_MCP_Access_Control.php:249-253`), F017 (`AbilityExposureGate.php:98-119`), and F020 (`ToolExposureGate.php:113-136`) all follow this pattern. Grep gate for new code that touches a vendor object's accessor: `grep -rEn 'instanceof.*\\\\.*McpServer|->get_id\(\)' includes/` MUST return zero matches OR the match MUST be inside an `if` block whose header is `method_exists( ..., 'get_server_id' )`.

**Evidence**
- `docs/security-reviews/2026-07-09-020-per-server-tool-selection-plan-v2.md §SEC-020-007` — full analysis.
- `includes/MCP/ToolExposureGate.php:113-136` — correct shipped pattern.
- `includes/MCP/AbilityExposureGate.php:98-119` — F017 canonical reference.
- `includes/AccessControl/AcrossAI_MCP_Access_Control.php:249-253` — F015 canonical reference.

**Where to look next**

For every vendor object accessed via WordPress action/filter payload, check the vendor source for the exact class name + accessor signature BEFORE writing the check. Casual documentation and README snippets often lag behind the vendor's actual namespace layout.

---

### B25 — Redundant `apiFetch.createRootURLMiddleware` in admin JS risks double-slash 404

**Status**: Active (F020 — 2026-07-09)
**Scope**: Admin-context React/JS bundles enqueued via `admin_enqueue_scripts`.
**Tags**: `apifetch, middleware, wp-api-settings, redundancy, double-slash-404, silent-failure, admin-js`

**Why this is durable**

WordPress admin JS bundles enqueued on plugin screens automatically inherit `wpApiSettings.root` from WordPress core; `@wordpress/api-fetch` uses that as its default rootURL. Explicitly wiring `apiFetch.use( apiFetch.createRootURLMiddleware( config.restApiRoot + '/' ) )` is redundant AND, when `config.restApiRoot` is already `untrailingslashit`-clean (per B17), risks silent 404s: combining a trailing-`/` base with paths that start with `/` produces `//`-doubled URLs that WordPress routes as 404. F020's initial mount function shipped this pattern; F017's `src/js/abilities.js:95` correctly wires ONLY `createNonceMiddleware` and leaves URL rooting to core.

**Pattern to prevent**

For admin-context JS, wire ONLY the nonce middleware:

```javascript
// WRONG — redundant + double-slash risk:
if ( config.nonce ) {
    apiFetch.use( apiFetch.createNonceMiddleware( config.nonce ) );
}
if ( config.restApiRoot ) {
    apiFetch.use( apiFetch.createRootURLMiddleware( config.restApiRoot + '/' ) );
}

// RIGHT — matches F017 abilities.js:95, relies on wpApiSettings.root:
if ( config.nonce ) {
    apiFetch.use( apiFetch.createNonceMiddleware( config.nonce ) );
}
```

Only add `createRootURLMiddleware` when the JS runs OUTSIDE an admin script context (e.g. in a public block that WordPress doesn't auto-configure, in a mail-template preview, or against a separate REST host).

**Evidence**
- `docs/security-reviews/2026-07-09-020-per-server-tool-selection-staged.md §SEC-020-STG-001` — full analysis.
- `src/js/abilities.js:95` — F017 canonical reference (nonce middleware only).

**Where to look next**

Grep gate for admin JS: `grep -rEn 'createRootURLMiddleware' src/js/`. Every hit needs justification: is this JS enqueued in a WordPress admin context (`admin_enqueue_scripts`)? If yes, delete the wire. Companion check: `grep -rEn '\+ \x27/\x27\)' src/js/` catches trailing-slash concatenation patterns that trip B17.

---

### B26 — Governance-gate scope drift: grep gates that hard-code a directory allow-list silently skip newly-added layers

**Status**: Active (F021 T118d added — 2026-07-12)
**Scope**: Project-specific verification gates under `bin/verify-*.sh` that use `grep -r` against a hard-coded set of directories to enforce a layering rule.
**Tags**: `grep, gate-hygiene, layer-scope, silent-pass, boundary, refactor-hazard, verification-gate`

**Why this is durable**

Related to but distinct from B15 (regex form completeness). B15 is about the *shape* of the pattern (bare-name vs FQN); B26 is about the *scope* of the scan (which directories the pattern is applied against). Both are grep-based verification-gate hygiene failures with silent-pass symptoms — the gate reads green in CI, the boundary is broken. F021's T118c gate scanned only `includes/OAuth/**` when checking "Controllers MUST NOT touch `$wpdb`"; the F024 nested-tabs work added `global $wpdb` + direct `\...\OAuthClients\Query::instance()` calls to `admin/Partials/ServerTabs/AIConnectorsTab.php:273-326` (the presentation layer, participating in the same layering rule). The gate passed for 24+ hours; the architecture review found it as V2.

**Pattern to prevent**

Every layering gate MUST enumerate every layer that participates in the layering rule, not just the layer being called out in the rule name. Concrete checklist:

1. **Enumerate all layers**: For a rule like "X MUST NOT touch Y", list every directory/namespace pattern that could contain code standing in for X. For F021 T118c, "no `$wpdb` above the Repository line" applies to Controllers AND Partials AND Renderers AND anywhere else that instantiates Query classes.
2. **Add the layer to the gate when the layer is added to the codebase**: When introducing a new admin-Partial subdirectory, new REST controller subclass, or new namespace, audit every `bin/verify-*.sh` for whether the scan set needs to grow.
3. **Validate every new gate against a known violation**: A gate that has never seen red is not a gate — it is a decoration. Before shipping `T118d`, verify it fires on the pre-fix code, then re-run after the fix and confirm green.
4. **Prefer inclusive base directories over per-layer allow-lists** when possible: `grep -rEn ... includes/ admin/Partials/ public/Partials/` is more robust than an exhaustive per-namespace list, because it grows with the codebase.

**Evidence**
- `bin/verify-f021-gates.sh` (pre-fix): T118c grep set = `includes/OAuth/Discovery/Authorization/Token/ClientRegistration/TokenValidator/UserLifecycle/Cleanup/OAuthRouter/PKCE.php` — 9 files, controllers only.
- `admin/Partials/ServerTabs/AIConnectorsTab.php:273-326` (pre-fix): direct `\...\OAuthClients\Query::instance()` + `global $wpdb; $wpdb->get_var(...)` — passed T118c silently.
- `bin/verify-f021-gates.sh` (post-fix 2026-07-12): T118d added to scan `admin/Partials/ServerTabs/AIConnectorsTab.php` for both `$wpdb` and `\...\OAuth*\Query::instance` patterns — fires red on the pre-fix code before R2 refactor.
- Architecture review report 2026-07-12 V2 + V3 findings.

**Where to look next**

Grep for governance gate drift: `grep -rEn 'grep.*includes/[A-Z]' bin/verify-*.sh` → every hit's directory list must be reviewed for completeness whenever a new top-level PHP directory pattern is added under `admin/`, `public/`, or `includes/`. Related: [[D22]] (fold-in tracking — same failure mode class); [[B15]] (regex form completeness).

---

### B27 — GitHub Actions matrix-cell check names are brittle for `required_status_checks.contexts` — matrix cells register as separate check names that shift when the matrix expands/contracts

**Status**: Active (F021 branch protection setup — 2026-07-12)
**Scope**: GitHub Actions matrix workflows + repository branch-protection `required_status_checks.contexts` API config.
**Tags**: `github-actions, matrix, required-status-checks, brittle-pinning, ci-drift, branch-protection`

**Why this is durable**

Applies to any repo that uses GH Actions matrix workflows + branch protection with `required_status_checks.contexts`. The check-run naming pattern is a stable GH Actions API contract; the brittleness follows deterministically from that contract. Not a bug in GH Actions — a bug in how the two features are wired together at the operator level.

**Pattern to prevent**

When a workflow uses a `strategy.matrix`, GitHub creates one check run per matrix cell with a name derived from the job's `name:` field + the matrix combination (e.g. `PHPUnit (pure) — PHP 8.1`, `PHPUnit (pure) — PHP 8.2`, ...). Pinning these in `repos/{owner}/{repo}/branches/{branch}/protection` → `required_status_checks.contexts` produces three failure modes:

1. **Adding a new matrix cell** (e.g. PHP 8.5): creates a NEW check name NOT in the required list → merges no longer wait for it. Silent coverage loss.
2. **Removing a matrix cell** (e.g. dropping PHP 8.1): leaves a stale required-name that will never report → merges block indefinitely. Loud but confusing.
3. **Renaming the job's `name:`**: breaks all pinned matrix cells atomically → all cells become stale required-names. Merges block until the operator manually updates protection settings.

Concrete remediation options in order of preference:

1. **Prefer non-matrix single-job workflows for gate checks** (PHPCS, PHPStan, PHPCompat, ESLint, validate-packages, project-specific grep gates). One check name per workflow, stable across matrix changes. Pin these.
2. **Use matrix workflows for coverage only** (PHPUnit across PHP versions). Do NOT pin matrix cells in branch protection. Accept that a PR could theoretically pass with one PHP cell red; enforce elsewhere (e.g. required review from CODEOWNERS who spot-check the matrix).
3. **Meta-job pattern** when you MUST enforce a matrix: add a single `jobs.gate` job with `needs: [phpunit]` and pin the meta-job name instead. Costs one extra scheduled job per PR; buys a stable pinned name.
4. **Audit protection settings whenever the workflow matrix changes**: cross-check `git grep -l 'strategy:' .github/workflows/` × `gh api repos/{owner}/{repo}/branches/main/protection` `required_status_checks.contexts`. Every workflow with a matrix should either NOT appear in the list, or appear via its meta-job name.

**Evidence**
- `.github/workflows/phpunit.yml` (2026-07-12): matrix `[8.1, 8.2, 8.3, 8.4]` — 4 matrix cells producing checks `PHPUnit (pure) — PHP 8.1`, `... 8.2`, `... 8.3`, `... 8.4` for the pure job and 2 more (`PHPUnit (integration) — PHP 8.1 / WP latest`, `... 8.4 / WP latest`) for the integration job.
- `acrossai-co/acrossai-mcp-manager` branch protection (2026-07-12): `required_status_checks.contexts` = 6 non-matrix check names (PHPCS, PHPStan, PHPCompat, ESLint, validate-packages, F021 gates). PHPUnit intentionally omitted.
- Architecture review + workflow-setup session 2026-07-12.

**Where to look next**

Before applying branch protection: `gh api repos/{owner}/{repo}/actions/runs?per_page=1 --jq '.workflow_runs[0].check_run_url'` and inspect the actual check names GitHub assigns. For every matrix workflow, decide: pin the meta-job, or omit and rely on CODEOWNERS. Never pin raw matrix-cell names unless the matrix values are frozen at the plugin's supported-version floor (rare).

---

### B28 — Freemius auto-submenus require BOTH `menu.<key>` AND the corresponding `has_<key>` / `is_<key>` at `fs_dynamic_init()` top level (silent no-render otherwise)

**Status**: Active (Feature 022 — 2026-07-13)
**Scope**: Every consumer of the Freemius WordPress SDK (any AcrossAI plugin using `\AcrossAI_Addon\AddonsPage` from `acrossai-co/main-menu`, plus any future direct Freemius consumer).
**Tags**: `freemius, two-level-enablement, menu-config, fs_dynamic_init, silent-no-render, generalizable`

**Symptom**

Setting `menu.<key> => true` in the Freemius `fs_dynamic_init()` config array produces zero visible effect for many keys — the submenu row simply does not appear under the plugin's parent menu, with no error log line, no admin notice, and no PHP warning. F022 hit this exact failure for the Add-ons row: `fs_menu.addons => true` was passed via the `acrossai-co/main-menu` 0.0.16 `fs_menu` override, but the Add-ons submenu remained invisible on wp-admin for ~5 rounds of diagnosis before the SDK-level gate was found.

**Root cause**

The Freemius SDK enables auto-submenus via a **two-level check** that is not documented in the SDK-config reference. Each `menu.<key>` boolean is AND'd with an independent top-level fs_dynamic_init capability flag at render time. Both must be `true` for the row to appear:

| `menu.<key>` | Also gated on (top-level fs_dynamic_init) | SDK code path |
|---|---|---|
| `menu.addons` | `has_addons => true` | `class-freemius.php:18964` — `if ( $this->has_addons() ) { ... add_submenu_item('addons', ...) }` |
| `menu.pricing`, `menu.upgrade` | `has_paid_plans => true` (and premium-plan config) | premium-flow gates around `_pricing_page_render` / `_upgrade_page_render` |
| `menu.account` | `is_registered() === true` (opt-in complete) | `class-freemius.php:18913` — `if ( ! WP_FS__DEMO_MODE && $this->is_registered() ) { ... add_submenu_item('account', ...) }` |
| `menu.contact` | (no additional flag — just `menu.contact === true`) | direct `add_submenu_item()` call, no capability gate |
| `menu.support` | (no additional flag — just `menu.support === true`) | direct `add_submenu_item()` call, no capability gate |

The `contact` and `support` keys are the ONLY ones that work with a single-level enablement — every other key needs a second flag.

**Decision (Prevention Recipe)**

When enabling any Freemius auto-submenu on an AcrossAI plugin:

1. Set the `menu.<key>` boolean via `AddonsPage`'s `fs_menu` override in `includes/Main.php`.
2. Grep the SDK for the render gate on that key: `grep -n "'<key>'\|has_<key>\|is_<key>\|_<key>_page_render" vendor/freemius/wordpress-sdk/includes/class-freemius.php | head -20`.
3. If a second-level flag is required and no override key exists on `AddonsPage`, extend the vendor (see F022 Phase 4e — `fs_has_addons` was added exactly this way in main-menu 0.0.18).
4. Verify at least one submenu row renders in wp-admin after opt-in completes; NEVER ship a "menu enabled" change without visual confirmation.

**Tradeoffs / Prevention**
- Gained: no more silent-no-render debugging arcs on Freemius menu changes.
- Reconsider: if Freemius simplifies the SDK to single-flag enablement in a future release, this table becomes obsolete — verify the gate list before assuming the table is current.
- Related: `DEC-ADDONS-PAGE-VENDOR-CTOR-BOOT` (F022 corollary documents the `fs_menu` + `fs_has_addons` override API on `AddonsPage`); `DEC-FREEMIUS-DOUBLE-OPTIN-GATES-ACCOUNT` (F022 — covers the `is_registered()` half of the `menu.account` gate).

**Evidence**
- `vendor/freemius/wordpress-sdk/includes/class-freemius.php:18964` — Add-ons render gate.
- `vendor/freemius/wordpress-sdk/includes/class-freemius.php:18913` — Account render gate.
- F022 branch commits `ba27058` (plugin-side fix — pass `fs_has_addons => true`) and `a6a35ff` (vendor-side fix — expose `fs_has_addons` override in main-menu 0.0.18) — both trace back to this failure mode.
- Session log 2026-07-13 shows ~5 rounds of "why isn't Add-ons showing" diagnosis before the SDK gate was located.

**Where to look next**

When a new Freemius auto-submenu is proposed (e.g. Freemius adds a `menu.affiliation` or `menu.gdpr` in a future SDK release): grep the SDK immediately for the second-level flag before declaring the enable "done". If the second flag isn't yet exposed on `AddonsPage`, plan a `acrossai-co/main-menu` bump using the same override pattern as `fs_has_addons` (main-menu 0.0.18).

---

### 2026-07-14 - B29 — Vendor `add_action` inside `__construct` misses actions that already fired

**Status**
Active — F025 evidence 2026-07-14.

**Why this is durable**
Third-party WordPress packages routinely wire `add_action` calls inside their class `__construct()` or `init()` methods. If those classes are instantiated inside another hook's callback (e.g., our `Controller::initialize_adapter()` on `rest_api_init`), then any `add_action` for hooks that FIRE BEFORE the outer callback runs will silently miss — the listener attaches AFTER its target action already fired. `wp_get_ability()` returned empty at F025 POST-time for exactly this reason.

**Pattern**

```php
// Vendor code — Plugin::__construct() (we instantiate via ::instance() inside our rest_api_init).
class Plugin {
    public function __construct() {
        // Fires during WP `init` — already fired by the time our rest_api_init runs.
        add_action( 'wp_abilities_api_init', array( $this, 'register_default_abilities' ) );
    }
}
```

Symptom: `wp_get_abilities()` (or equivalent lookup) returns fewer entries than expected at REST/AJAX time. The missing entries were supposed to be registered by an action that fires EARLIER than the outer callback where the vendor is instantiated.

**Prevention / Detection**

1. Any REST route validating a vendor-registered slug via `wp_get_ability()` MUST include a manual smoke test (curl the endpoint) — static hook analysis is insufficient.
2. When a plugin-owned canonical source exists for the slug (like F025's `ToolPolicy::PROTOCOL_TOOLS`), prefer it over `wp_get_abilities()` for validation.
3. For vendor packages whose init pattern uses `add_action` inside `__construct`, document the required outer hook order OR bootstrap on `plugins_loaded` P0 for hooks that must catch `init`.

**Fixed by (F025)**

- FR-018: POST validation bypasses `wp_get_abilities()` for canonical protocol slugs.
- `ToolPolicy::PROTOCOL_TOOL_METADATA`: GET catalog fallback for reader-side visibility.

**Where to look next**

- `vendor/wordpress/mcp-adapter/includes/Core/McpAdapter.php:120`
- `includes/REST/ToolsController.php` FR-018 comments
- `docs/security-reviews/2026-07-13-025-server-tools-registration-hooks-plan-v2.md` §SEC-025-v2-2

---

### 2026-07-14 - B30 — Plugin composer shadowing vendor discovery must mirror the vendor's type-filter semantic exactly

**Status**
Active — new bug pattern surfaced during F026 v1 → v2 fold-in.

**Why this is durable**
When plugin code composes a slug list that will be handed to a vendor API which
INTERNALLY dispatches by ability type (tool vs resource vs prompt), the plugin
composer MUST filter by the same `mcp.type` key the vendor's own discovery
helper uses — including its `?? 'tool'` default when unset. Missing this
filter causes cross-type advertisement: e.g. a `mcp.type === 'resource'`
ability leaks into `tools/list`, then `tools/call` on it rejects at
invocation time because the vendor's call-time dispatcher can't find it in
the tool registry.

**Symptom**
- `tools/list` includes ability slugs that `tools/call` immediately rejects
  with `_doing_it_wrong` or 404.
- `resources/list` and `prompts/list` are empty despite public resource/prompt
  abilities being registered.

**Root cause**
Vendor `DefaultServerFactory::discover_abilities_by_type( 'tool' )` filters by
`$meta['mcp']['type'] ?? 'tool'`. F026 v1's `compose_effective_tools_for_row()`
missed the filter — it included every ability where `ExposureResolver::resolve()`
was true. Fixed in F026 v2 by adding `AbilityDiscovery::for_server( $id, $type )`
which mirrors the vendor's `?? 'tool'` default.

**Prevention**
- When authoring a composer that shadows or supplements a vendor discovery
  helper, first read the vendor helper's filter chain end-to-end. Mirror EVERY
  filter step (including `?? 'default'` fallbacks) in the plugin composer.
- Write at least one test case per vendor-recognized type that registers a
  scratch ability with that type and asserts it appears in ONLY the matching
  composer output — not the others. F026 v2 added
  `test_for_server_tool_type_includes_only_tool_typed_public_abilities` etc.

**Where to look next**
- `vendor/wordpress/mcp-adapter/includes/Servers/DefaultServerFactory.php:141`
  (canonical vendor semantic)
- `includes/Database/MCPServer/AbilityDiscovery.php:59` (mirror)
- `tests/phpunit/Database/MCPServer/AbilityDiscoveryTest.php` (per-type coverage cases)

---

### 2026-07-15 - B31 — Vendor tool-name sanitization silently breaks slug-compare bypass constants

**Status**
Active — new bug pattern surfaced during F026 v3 refactor arc when the three built-in meta tools became first-class execution paths.

**Why this is durable**
When a plugin gate compares `$tool_name` (as passed to
`mcp_adapter_pre_tool_call`) against a constant list of "always-bypass" slugs,
it MUST account for vendor's `McpNameSanitizer::sanitize_name` which swaps `/`
→ `-` in ability slugs at MCP tool registration time
(`vendor/wordpress/mcp-adapter/includes/Domain/Utils/McpNameSanitizer.php:73`).
The client-facing tool name — what appears in `tools/list` and what the AI
client sends back on `tools/call` — is the HYPHEN form, while the raw ability
slug (registered via `wp_register_ability()`) is the SLASH form. Bypass
constants that list only the slash form never match.

**Symptom**
- Gate rejects a call with a `WP_Error` like `acrossai_mcp_tool_not_added`
  ("This tool is not enabled on this MCP server.") on a slug that's explicitly
  in the plugin's own "always-allowed" list.
- Symptom only manifests when the plugin's own code actually invokes the
  vendor-registered tool via `tools/call`. Pre-invocation, the tool exists in
  the vendor registry (indexed by the sanitized name) but the gate rejects it
  before dispatch.

**Root cause**
Vendor `RegisterAbilityAsMcpTool::build_tool_data()` (`vendor/.../Domain/Tools/
RegisterAbilityAsMcpTool.php:211`) calls
`McpNameSanitizer::sanitize_name($this->ability->get_name())` and stores the
sanitized result as the tool DTO's `name`. Vendor's `McpComponentRegistry::
add_mcp_tool()` keys `$mcp_tools[$sanitized_name] = $tool`. So the
end-to-end tool name — client-facing AND filter-facing — is the sanitized
form. F020 `ToolExposureGate::EXCLUDED_SLUGS` originally listed only the raw
form; bypass never matched; F020 denied all three built-in meta tools.

**Prevention**
- When authoring a gate that compares `$tool_name` against a bypass constant
  (or against a slug-set from the DB), list BOTH forms explicitly, or apply
  `McpNameSanitizer::sanitize_name` to the constant at compare time. Prefer
  the both-forms approach — it avoids vendor coupling at gate time and makes
  the intent explicit in the source.
- Write at least one test case per bypassed slug that calls the gate with
  the SANITIZED form and asserts it passes through. F020's test class
  (`tests/phpunit/MCP/ToolExposureGateTest.php`) does this per protocol slug.
- General principle: whenever plugin code compares a slug-derived string
  against a value that flows through a vendor-provided normalization
  pipeline, read the vendor's pipeline end-to-end before writing the
  compare. This gap survived undetected for months because pre-070ffe2
  nobody actually invoked the affected tools via `tools/call`.

**Where to look next**
- `vendor/wordpress/mcp-adapter/includes/Domain/Utils/McpNameSanitizer.php:73`
  (canonical sanitizer)
- `vendor/wordpress/mcp-adapter/includes/Domain/Tools/RegisterAbilityAsMcpTool.php:211`
  (where sanitization is applied)
- `includes/MCP/ToolExposureGate.php:55` (fixed EXCLUDED_SLUGS constant)
- `tests/phpunit/MCP/ToolExposureGateTest.php` (regression guard)
- Commit `69e689c` (the fix)

---

### 2026-07-15 - B32 — Filter defaults MUST express the plugin's canonical semantic (never a partial derivation)

**Status**
Active — new bug pattern surfaced during F026 v3 fix arc (commit `e0189b0`).

**Why this is durable**
When plugin code fires `apply_filters()` with a default value, that default
IS the authoritative expression of the plugin's semantic when no callback
intervenes. If the default is a partial derivation (e.g., "check a static
metadata flag" instead of "consult the canonical resolver"), consumers see
the SHORTCUT behavior — the correct behavior only kicks in if they know to
hook the filter and re-add the missing logic. This silently ignores any
higher-precedence rules (per-server overrides, deprecation shims, feature
flags) that the resolver would have honored.

**Symptom**
- Feature reads "correctly" when a canonical function is called directly
  (e.g., `ExposureResolver::resolve()` returns the right answer), but
  reads "incorrectly" through a filter-mediated path (e.g., a filter whose
  default is `meta.mcp.public` only).
- User-facing count / list is a strict subset of what the operator expects.
  Operator-configured per-server overrides are silently ignored.
- Distinguishing tell: adding a `var_dump` at the filter default computation
  shows the wrong value; removing/replacing that computation with a call to
  the canonical resolver fixes it.

**Root cause**
Filter author reasoned "the DEFAULT is the trivial static case; anyone who
wants richer behavior can hook the filter to add it". This works for opt-in
enhancements (color themes, formatting) but FAILS for enforcement semantics
(authorization, per-tenant visibility, per-server overrides) — those must
be baked into the default because the plugin owns the semantic, not the
caller.

**Prevention**
- When designing a filter that gates security or per-context enforcement
  decisions (visibility, exposure, ownership, capability), the DEFAULT MUST
  be the canonical resolver's output.
- If a canonical resolver exists in the plugin (e.g., `ExposureResolver::
  resolve()` per `DEC-ABILITY-OVERRIDE-RESOLUTION`), the filter default
  MUST call it — even if that seems redundant, because callers depending on
  the filter's decision cannot know the resolver exists.
- Write at least one test case that seeds the CANONICAL state (e.g., a
  per-server override row) and asserts the filter's output honors it
  WITHOUT any test-registered callback intervening. This is the exact case
  that catches the bug.
- **General principle**: filters exist to let callers ADD or MUTATE the
  plugin's decision, not to let the plugin outsource its decision to
  callers.

**Where to look next**
- `includes/Abilities/AbilityHelpers.php:61-108` (`apply_exposure_filter`
  post-fix — default = `ExposureResolver::resolve()`)
- `includes/Database/MCPServerAbility/ExposureResolver.php` (canonical
  resolver)
- Commit `e0189b0` (the fix)
- `tests/phpunit/Abilities/DiscoverTest.php` — cases
  `test_execute_includes_non_public_ability_with_f017_override_when_holder_set`
  and `test_execute_excludes_public_ability_with_f017_override_disabled_when_holder_set`
  (regression guards)
- `DEC-ABILITY-OVERRIDE-RESOLUTION` (the canonical-resolver principle this
  bug pattern violated)
