# Quickstart: Verifying the AI Connectors Migration

**Feature**: 040-migrate-ai-connectors-to-companion
**Phase**: 1 (Design & Contracts)
**Purpose**: Three concrete recipes to verify the feature works end-to-end for each user population defined in the spec. Each recipe is a WP-CLI + `curl` sequence with expected outputs. `/speckit-tasks` will convert these into concrete acceptance-test task steps.

**Assumptions for all recipes**:

- Local test site available at `https://wordpress-7-0.local` (mcp-manager's dev environment).
- WP-CLI installed and on PATH.
- Both plugin worktrees present: `wp-content/plugins/acrossai-mcp-manager/` and `wp-content/plugins/acrossai-ai-connectors/`.
- MCP Manager branch `040-migrate-ai-connectors-to-companion` checked out (this feature's changes applied).
- Companion at v0.5.0+ (audited PASS).

---

## Recipe A — Free-user path (no add-on, no OAuth history)

**Scenario**: Site has only mcp-manager active. Never installed the add-on. `wp_acrossai_mcp_oauth_tokens` table has no rows (or does not exist).

**Setup**:

```bash
# Ensure add-on is inactive
wp plugin deactivate acrossai-ai-connectors --path=/Users/raftaar1191/local-sites/wordpress-7-0/app/public

# Verify the tokens table is either absent or empty (either is fine)
wp db query "SELECT COUNT(*) FROM wp_acrossai_mcp_oauth_tokens" --path=/Users/raftaar1191/local-sites/wordpress-7-0/app/public 2>&1 || echo "table absent — OK"

# Ensure mcp-manager is active and on 0.2.0 (from this feature)
wp plugin activate acrossai-mcp-manager --path=/Users/raftaar1191/local-sites/wordpress-7-0/app/public
wp plugin get acrossai-mcp-manager --field=version --path=/Users/raftaar1191/local-sites/wordpress-7-0/app/public
# Expected: 0.2.0
```

**Verify**:

```bash
# 1. mcp-manager activates without fatal — check active status
wp plugin is-active acrossai-mcp-manager --path=/Users/raftaar1191/local-sites/wordpress-7-0/app/public
# Expected: exit 0

# 2. PHP fatal check — visit the admin dashboard, assert no white screen
curl -s -o /dev/null -w "%{http_code}" -b cookies.txt -c cookies.txt "https://wordpress-7-0.local/wp-admin/index.php"
# Expected: 302 or 200 (never 500)

# 3. npm tab renders — visit tab=npm on server 1
curl -s -b cookies.txt "https://wordpress-7-0.local/wp-admin/admin.php?page=acrossai_mcp_manager&action=edit&server=1&tab=npm" | grep -q 'npm' && echo "PASS: npm tab present" || echo "FAIL"

# 4. clients tab renders
curl -s -b cookies.txt "https://wordpress-7-0.local/wp-admin/admin.php?page=acrossai_mcp_manager&action=edit&server=1&tab=clients" | grep -q 'clients' && echo "PASS: clients tab present" || echo "FAIL"

# 5. AI Connectors tab does NOT appear (add-on inactive)
curl -s -b cookies.txt "https://wordpress-7-0.local/wp-admin/admin.php?page=acrossai_mcp_manager&action=edit&server=1" | grep -q 'tab=ai-connectors' && echo "FAIL: AI Connectors tab leaked" || echo "PASS: AI Connectors tab correctly absent"

# 6. No admin notice fires (per Q6, no notice is built at all)
curl -s -b cookies.txt "https://wordpress-7-0.local/wp-admin/index.php" | grep -q 'AcrossAI AI Connectors' && echo "FAIL: unexpected admin notice" || echo "PASS: no admin notice"

# 7. Discovery API's ai_connector category is empty (FR-019)
curl -s "https://wordpress-7-0.local/wp-json/acrossai-mcp-manager/v1/discovery/methods" | python3 -c "import json,sys; d=json.load(sys.stdin); assert all(m['category']!='ai_connector' for m in d.get('methods',[])), 'ai_connector leaked'; print('PASS: ai_connector category empty when add-on absent')"
```

**Expected result**: All 7 checks report PASS. Free-user experience is completely undisturbed.

---

## Recipe B — Premium-user-seamless path (add-on active, prior OAuth)

**Scenario**: Site has both plugins active. `wp_acrossai_mcp_oauth_tokens` has ≥1 valid bearer token issued before the migration. User expects zero re-authorization and continued MCP request success.

**Setup**:

```bash
# Activate both plugins
wp plugin activate acrossai-mcp-manager acrossai-ai-connectors --path=/Users/raftaar1191/local-sites/wordpress-7-0/app/public

# Verify a pre-existing bearer token exists (from prior manual OAuth setup)
TOKEN_HASH_COUNT=$(wp db query "SELECT COUNT(*) FROM wp_acrossai_mcp_oauth_tokens WHERE expires_at > UNIX_TIMESTAMP()" --skip-column-names --path=/Users/raftaar1191/local-sites/wordpress-7-0/app/public)
echo "Live tokens: $TOKEN_HASH_COUNT"
# Expected: ≥ 1

# You need a plaintext bearer token that maps to one of these rows.
# In practice: use the token generated during your last Claude Web / ChatGPT / Grok connection setup.
# For this recipe, export it as:
export PRE_MIGRATION_TOKEN="<paste-your-pre-migration-bearer-token-here>"
```

**Verify**:

```bash
# 1. Both plugins are active
wp plugin is-active acrossai-mcp-manager --path=/Users/raftaar1191/local-sites/wordpress-7-0/app/public
wp plugin is-active acrossai-ai-connectors --path=/Users/raftaar1191/local-sites/wordpress-7-0/app/public
# Both expected: exit 0

# 2. mcp-manager's OAuth AuthorizationController class is GONE (ownership handoff)
wp eval 'var_dump(class_exists("\\AcrossAI_MCP_Manager\\Includes\\OAuth\\AuthorizationController"));' --path=/Users/raftaar1191/local-sites/wordpress-7-0/app/public
# Expected: bool(false)

# 3. Companion's TokenValidator class exists (add-on took over)
wp eval 'var_dump(class_exists("\\AcrossAI_AI_Connectors\\Includes\\OAuth\\TokenValidator"));' --path=/Users/raftaar1191/local-sites/wordpress-7-0/app/public
# Expected: bool(true)

# 4. Discovery endpoint returns preserved URLs
curl -s "https://wordpress-7-0.local/.well-known/oauth-authorization-server" | python3 -c "
import json, sys
d = json.load(sys.stdin)
assert d['authorization_endpoint'].endswith('/wp-json/acrossai-mcp-manager/v1/oauth/authorize'), f'authorize: {d[\"authorization_endpoint\"]}'
assert d['token_endpoint'].endswith('/wp-json/acrossai-mcp-manager/v1/oauth/token'), f'token: {d[\"token_endpoint\"]}'
assert d['registration_endpoint'].endswith('/wp-json/acrossai-mcp-manager/v1/oauth/register'), f'register: {d[\"registration_endpoint\"]}'
print('PASS: discovery URLs preserved under acrossai-mcp-manager/v1')
"

# 5. Pre-existing bearer token still authenticates (the whole point of the migration)
SERVER_SLUG="test"  # adjust to a real slug on your test site
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" -H "Authorization: Bearer $PRE_MIGRATION_TOKEN" \
  "https://wordpress-7-0.local/wp-json/acrossai-mcp-manager/v1/mcp/$SERVER_SLUG")
echo "HTTP: $HTTP_CODE (expected 200)"
# CRITICAL: must be 200, NOT 401

# 6. Cron event still scheduled under the preserved name
wp cron event list --path=/Users/raftaar1191/local-sites/wordpress-7-0/app/public | grep 'acrossai_mcp_manager_oauth_cleanup'
# Expected: exactly one entry, recurrence 'daily'

# 7. Tables preserved — DDL unchanged
wp db query "SHOW CREATE TABLE wp_acrossai_mcp_oauth_tokens\G" --path=/Users/raftaar1191/local-sites/wordpress-7-0/app/public
# Expected: identical DDL to pre-migration snapshot (capture and diff)

# 8. AI Connectors tab renders under the same URL, served by companion
curl -s -b cookies.txt "https://wordpress-7-0.local/wp-admin/admin.php?page=acrossai_mcp_manager&action=edit&server=1&tab=ai-connectors" | grep -q 'AI Connectors' && echo "PASS: tab renders" || echo "FAIL"

# 9. Discovery API's ai_connector category is populated (FR-019 fallback path — add-on IS active)
curl -s "https://wordpress-7-0.local/wp-json/acrossai-mcp-manager/v1/discovery/methods" | python3 -c "
import json, sys
d = json.load(sys.stdin)
ai = [m for m in d.get('methods',[]) if m['category']=='ai_connector']
assert len(ai) > 0, 'ai_connector category unexpectedly empty'
print(f'PASS: {len(ai)} ai_connector entries')
"
```

**Expected result**: All 9 checks report PASS. Zero re-authorization. Zero data loss.

---

## Recipe C — Premium-degraded path (add-on missing, prior OAuth)

**Scenario**: Site had OAuth working before, but the add-on is somehow deactivated (WP-CLI bypass — normally impossible via UI because AI Connectors declares `Requires Plugins: acrossai-mcp-manager`, so this is the WP-CLI edge case).

**Setup**:

```bash
# Activate mcp-manager, deactivate add-on (may require --skip-plugins to bypass Requires Plugins check
# if it were declared in reverse; since it isn't, plain deactivate works)
wp plugin activate acrossai-mcp-manager --path=/Users/raftaar1191/local-sites/wordpress-7-0/app/public
wp plugin deactivate acrossai-ai-connectors --path=/Users/raftaar1191/local-sites/wordpress-7-0/app/public

# Verify the token table has data (pre-migration OAuth activity)
wp db query "SELECT COUNT(*) FROM wp_acrossai_mcp_oauth_tokens" --skip-column-names --path=/Users/raftaar1191/local-sites/wordpress-7-0/app/public
# Expected: ≥ 1
```

**Verify**:

```bash
# 1. mcp-manager still activates cleanly (no hard dependency on add-on)
wp plugin is-active acrossai-mcp-manager --path=/Users/raftaar1191/local-sites/wordpress-7-0/app/public
# Expected: exit 0

# 2. No PHP fatal on admin dashboard
curl -s -o /dev/null -w "%{http_code}" -b cookies.txt "https://wordpress-7-0.local/wp-admin/index.php"
# Expected: 302 or 200 (never 500)

# 3. NO admin notice fires (per Q6 — no notice is built)
curl -s -b cookies.txt "https://wordpress-7-0.local/wp-admin/index.php" | grep -q 'AcrossAI AI Connectors' && echo "FAIL: unexpected notice" || echo "PASS: no notice (as spec'd)"

# 4. OAuth bearer tokens correctly return 401 (add-on gone, no token validator)
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" -H "Authorization: Bearer $PRE_MIGRATION_TOKEN" \
  "https://wordpress-7-0.local/wp-json/acrossai-mcp-manager/v1/mcp/$SERVER_SLUG")
echo "HTTP: $HTTP_CODE (expected 401)"
# Expected: 401 — this is CORRECT behavior in the degraded state

# 5. Tables preserved despite add-on deactivation
wp db query "SELECT COUNT(*) FROM wp_acrossai_mcp_oauth_tokens" --skip-column-names --path=/Users/raftaar1191/local-sites/wordpress-7-0/app/public
# Expected: same count as before (add-on deactivation does not drop tables — only uninstall does)

# 6. npm / clients tabs still work (free-tier connection paths unaffected)
curl -s -b cookies.txt "https://wordpress-7-0.local/wp-admin/admin.php?page=acrossai_mcp_manager&action=edit&server=1&tab=npm" | grep -q 'npm' && echo "PASS" || echo "FAIL"
curl -s -b cookies.txt "https://wordpress-7-0.local/wp-admin/admin.php?page=acrossai_mcp_manager&action=edit&server=1&tab=clients" | grep -q 'clients' && echo "PASS" || echo "FAIL"

# 7. AI Connectors tab correctly absent (companion is what registers it; companion is inactive)
curl -s -b cookies.txt "https://wordpress-7-0.local/wp-admin/admin.php?page=acrossai_mcp_manager&action=edit&server=1" | grep -q 'tab=ai-connectors' && echo "FAIL: tab present" || echo "PASS: tab absent"

# 8. Reactivation is idempotent — bearer tokens resume working after re-activating the add-on
wp plugin activate acrossai-ai-connectors --path=/Users/raftaar1191/local-sites/wordpress-7-0/app/public
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" -H "Authorization: Bearer $PRE_MIGRATION_TOKEN" \
  "https://wordpress-7-0.local/wp-json/acrossai-mcp-manager/v1/mcp/$SERVER_SLUG")
echo "HTTP after reactivation: $HTTP_CODE (expected 200)"
# Expected: 200 — reactivation restores OAuth without re-authorization
```

**Expected result**: All 8 checks report PASS. The degraded state is graceful (no fatals, no scary UI leaks), and remediation is instant on reactivation.

---

## Pre-flight and Post-flight Grep (FR-016 DoD gate)

Run before merging:

```bash
cd /Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/plugins/acrossai-mcp-manager

# Post-flight callers grep — MUST return zero matches
grep -rEn '(new (Authorization|Token|ClientRegistration|ConnectorAdmin|Discovery)Controller|use .*(AuthorizationController|TokenController|ClientRegistrationController|ConnectorAdminController|DiscoveryController|OAuthRouter|PKCE|Cleanup|TokenValidator|BearerChallengeHeader|UserLifecycle|AccessTokenRepository|RefreshTokenRepository|ClientRepository|AuthCodeRepository|ScopeRepository|SecretsVault|RateLimiter|AbstractConnectorProfile|ConnectorProfileRegistry|ConnectorSettings|AIConnectorsTab|OAuthClients|OAuthTokens|OAuthAuthCodes|ConnectorApprovedUsers))' \
  --include='*.php' \
  includes/ admin/ public/ acrossai-mcp-manager.php uninstall.php tests/ 2>&1

# Expected: zero output lines.
# Exception: `public/Discovery/ConnectionMethodRegistry.php` will still contain a `ConnectorProfileRegistry`
# reference — but under the COMPANION's namespace (`AcrossAI_AI_Connectors\...`), which does NOT match
# the pattern above (which only looks for `AcrossAI_MCP_Manager\...` occurrences implicitly through
# `use` statements). If the grep pattern is expanded to also match companion-namespace references,
# the ConnectionMethodRegistry hit is expected and safe.
```

## DoD Command Reference

The full Definition of Done from `spec.md` §Success Criteria maps to these commands:

```bash
# 1. PHPCS
vendor/bin/phpcs --standard=WordPress -p .
# Expected: zero errors, zero warnings

# 2. PHPStan L8
vendor/bin/phpstan analyse -c phpstan.neon --level=8 --no-progress
# Expected: zero errors

# 3. ESLint
npm run lint:js
# Expected: zero errors (should be trivially green — we're deleting JS, not adding)

# 4. PHPUnit
composer test
# Expected: all remaining tests pass (OAuth/Connectors tests deleted per FR-015)

# 5. Composer autoload
composer dump-autoload
# Expected: zero warnings

# 6. Webpack build (no ai-connectors artifact produced)
npm run build
# Expected: succeeds; build/js/ai-connectors.* NOT produced; other bundles unchanged

# 7. Package validation
npm run validate-packages
# Expected: passes

# 8. Git tree check — deleted paths return nothing
git ls-tree HEAD -- includes/OAuth includes/Connectors includes/Database/OAuth includes/Database/ConnectorApprovedUsers admin/Partials/ServerTabs/AIConnectorsTab.php templates/oauth src/js/ai-connectors.js src/scss/ai-connectors.scss build/js/ai-connectors.js build/js/ai-connectors.css build/js/ai-connectors.asset.php specs/039-migrate-ai-connectors-to-companion 2>&1 | wc -l
# Expected: 0 (nothing tracked under any deleted path)
```

All eight DoD commands MUST pass before merge.
