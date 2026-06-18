# Quickstart — Phase 4: MCP Client Classes Manual Verification

**Date**: 2026-06-17 | **Branch**: `004-mcp-clients` | **Time budget**: ~10 minutes

This walk verifies a Phase 4 implementation has shipped cleanly. It does
NOT require WordPress to be running — pure service classes need only
PHP + PHPUnit.

---

## Prerequisite check

```bash
# 0.1 — PHPUnit harness must exist (carry-forward from Phase 2 RT-4 or
# T000 of this phase). If absent, STOP — set up the harness first.
ls tests/phpunit/ || echo "HARNESS MISSING — see Phase 2 RT-4"
ls phpunit.xml.dist || echo "PHPUNIT CONFIG MISSING"
ls vendor/bin/phpunit || echo "PHPUNIT NOT INSTALLED — composer install"
```

---

## Walk

### 1. File-existence + namespace verification

```bash
# All 8 files exist
for f in AbstractMCPClient ClaudeCodeClient ClaudeDesktopClient \
         CodexClient CursorClient CustomClient \
         GitHubCopilotClient VSCodeClient; do
    test -f "includes/MCPClients/${f}.php" || echo "MISSING: ${f}.php"
done

# All declare the namespace
grep -L "^namespace AcrossAI_MCP_Manager\\\\Includes\\\\MCPClients;" \
     includes/MCPClients/*.php
# Expected: empty (every file matches)
```

✅ **Pass** = all 8 files present and namespaced correctly (FR-012).

---

### 2. FR-008 — purity grep gates

```bash
# No hooks, DB, HTTP, cookies, raw output
grep -rnE 'add_action|add_filter|\$wpdb|wp_remote_(get|post)|setcookie|^[^*/]*\b(echo|print)\b' \
     includes/MCPClients/
# Expected: empty
```

✅ **Pass** = empty output (no purity violations).

---

### 3. FR-009 — no singleton

```bash
grep -rn 'public static function instance' includes/MCPClients/
# Expected: empty
```

✅ **Pass** = empty output (no singleton ceremony).

But:

```bash
grep -rn 'public static function get_all_clients' includes/MCPClients/
# Expected: 1 match in AbstractMCPClient.php
```

✅ **Pass** = exactly one match (the V3=both static factory in the
abstract base).

---

### 4. PHPUnit golden-fixture suite

```bash
vendor/bin/phpunit tests/phpunit/MCPClients/
```

✅ **Pass** = all tests green. Expected suite shape:

- `AbstractMCPClientTest` covers:
  - `testDeriveServerKeyMatrix` — 7 input/output pairs from research.md R2
  - `testSafeTokenReturnsPlaceholderOnEmpty`
  - `testSafeTokenReturnsRawOnNonEmpty`
  - `testRedactTokenFirstFourLastTwo`
  - `testRedactTokenEmptyReturnsEmptyMarker`
  - `testBuildServerUrlConcatenatesPathsCorrectly`
  - `testGetAllClientsReturnsExactlySevenClients`
  - `testGetAllClientsExcludesAbstractClass`
  - `testGetAllClientsReturnsSortedSlugs`
- `<Client>Test` (×7) each covers:
  - `testGetClientSlugMatchesSpec` — verifies the slug from FR-004
  - `testGetClientNameMatchesSpec` — verifies the name from FR-004
  - `testGetConfigSnippetWithTokenMatchesFixture` — fixture comparison
  - `testGetConfigSnippetEmptyTokenMatchesFixture` — fixture comparison
  - `testReturnTypeMatchesSpec` — `assertIsArray` or `assertIsString`

---

### 5. Slug uniqueness (FR-007)

```bash
php -r '
require_once "vendor/autoload.php";
$slugs = array_map(
    fn($c) => $c->get_client_slug(),
    AcrossAI_MCP_Manager\Includes\MCPClients\AbstractMCPClient::get_all_clients()
);
$dupes = array_diff_assoc($slugs, array_unique($slugs));
if ( empty($dupes) ) { echo "PASS: all 7 slugs unique\n"; }
else { echo "FAIL: duplicate slugs " . implode(",", $dupes) . "\n"; }
'
```

✅ **Pass** = `PASS: all 7 slugs unique`.

---

### 6. Smoke test — works without WP bootstrap (SC-003)

```bash
# Run a 1-liner that does NOT load wp-load.php
php -r '
require_once "vendor/autoload.php";
$c = new AcrossAI_MCP_Manager\Includes\MCPClients\ClaudeDesktopClient();
$out = $c->get_config_snippet(
    "https://example.com/wp-json/mcp/test-server",
    "secret123"
);
var_dump($out);
'
```

✅ **Pass** = the script runs WITHOUT any "Call to undefined function"
errors (no `wp_*` calls in the class bodies) AND the dumped array has:

- Top-level key `mcpServers`
- Inner key `test-server` (derived from URL)
- `env.WP_API_URL === "https://example.com/wp-json/mcp/test-server"`
- `env.WP_API_PASSWORD === "secret123"`

---

### 7. PHPCS / PHPStan gates

```bash
vendor/bin/phpcs includes/MCPClients/
# Expected: 0 errors, 0 warnings

vendor/bin/phpstan analyse includes/MCPClients/ --level=8
# Expected: 0 errors
```

✅ **Pass** = both clean.

---

## Definition of Done

If steps 1–7 all pass, Phase 4 ships. Mark the DoD gate checkboxes in
spec.md complete and proceed to Phase 2's RT-3 amendment (consumer
integration in `ApplicationPasswords::render_for_server`).
