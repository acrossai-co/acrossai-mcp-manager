# Phase 1 — Quickstart

End-to-end validation walkthrough for Feature 021. Run this after `/speckit-implement` completes to verify all Definition of Done gates + Success Criteria on a live install.

**Preconditions**:

- Fresh WordPress 6.9+ install, PHP 8.1+, InnoDB utf8mb4.
- Plugin `acrossai-mcp-manager` freshly activated on branch `021-oauth-2-1-implementation`.
- At least one MCP server registered.
- A stub connector-profile companion plugin (below) installed and activated.
- Test user account with `manage_options` capability.
- **HTTPS or LOCAL install** — OAuth 2.1 requires TLS for non-loopback callbacks.

---

## Setup — Install a stub connector profile

Save the following as `wp-content/mu-plugins/stub-connector-profile.php`:

```php
<?php
use AcrossAI_MCP_Manager\Includes\Connectors\AbstractConnectorProfile;

class StubConnectorProfile extends AbstractConnectorProfile {
    public function get_slug(): string { return 'stub-connector'; }
    public function get_name(): string { return 'Stub Connector'; }
    public function get_icon_url(): string { return 'https://placehold.co/64'; }
    public function get_redirect_uri_whitelist(): array {
        return array(
            'http://localhost:33333/callback',
            'https://client.example.com/callback',
        );
    }
    public function get_setup_instructions( array $server, string $client_id, string $client_secret ): string {
        return sprintf( '<pre>client_id=%s\nclient_secret=%s</pre>',
            esc_html( $client_id ), esc_html( $client_secret ) );
    }
    public function render_tab_section( array $server ): void {
        echo '<p>Stub connector is configured for this server.</p>';
    }
}

add_filter( 'acrossai_mcp_manager_connector_profiles', function ( array $p ) {
    $p[] = new StubConnectorProfile();
    return $p;
} );
```

---

## Step 1 — Fresh activation smoke test

1. Deactivate the plugin.
2. Drop OAuth tables + options (dev-only reset):
   ```sh
   wp db query "DROP TABLE IF EXISTS wp_acrossai_mcp_oauth_clients, wp_acrossai_mcp_oauth_tokens, wp_acrossai_mcp_oauth_auth_codes;"
   wp option delete acrossai_mcp_oauth_clients_db_version acrossai_mcp_oauth_tokens_db_version acrossai_mcp_oauth_auth_codes_db_version
   ```
3. Reactivate the plugin.
4. Verify:
   ```sh
   wp db query "SHOW TABLES LIKE 'wp_acrossai_mcp_oauth_%'"
   # Expected: 3 rows.
   wp option get acrossai_mcp_oauth_clients_db_version
   wp option get acrossai_mcp_oauth_tokens_db_version
   wp option get acrossai_mcp_oauth_auth_codes_db_version
   # Expected: 1.0.0 for each.
   wp cron event list | grep acrossai_mcp_manager_oauth_cleanup
   # Expected: 1 scheduled entry, next run within 24h.
   ```
5. Verify `wp-content/debug.log` is silent during reactivation.

Maps to: **FR-037, phantom-version guard, silent lifecycle**.

---

## Step 2 — Discovery metadata smoke test

```sh
curl -s https://<site>/.well-known/oauth-authorization-server | jq .
# Expected: valid JSON with all 11 required RFC 8414 fields.

curl -s "https://<site>/.well-known/oauth-protected-resource?resource=https://<site>/wp-json/mcp/v1" | jq .
# Expected: valid JSON echoing resource + issuer.

curl -sI https://<site>/.well-known/oauth-authorization-server | grep -Ei "^(cache-control|access-control-allow-origin):"
# Expected: Cache-Control: public, max-age=3600  and  Access-Control-Allow-Origin: *
```

Maps to: **FR-001, FR-002, FR-003**.

---

## Step 3 — AI Connectors tab renders + credential generation

1. Log in as `manage_options` user.
2. Navigate to `?page=acrossai_mcp_manager&action=edit&server_id={id}&tab=ai-connectors`.
3. Verify: the `Stub Connector` card renders with its icon + name + a **"Generate credentials"** button. If zero profiles were registered, the empty-state notice appears instead — remove the mu-plugin briefly to confirm.
4. Click **Generate credentials**. Verify:
   - The POST fires to `/wp-json/acrossai-mcp-manager/v1/oauth/generate-client`.
   - Response body includes `client_id` beginning with `server-{server_id}-stub-connector-` and a `client_secret` (64 hex).
   - The card now shows the profile's `get_setup_instructions` output with the raw values visible.
   - A row exists in the DB:
     ```sh
     wp db query "SELECT client_id, connector_slug FROM wp_acrossai_mcp_oauth_clients"
     # Expected: 1 row, client_id starts with server-1-stub-connector-, connector_slug=stub-connector.
     ```
5. Reload the page. Verify:
   - The raw `client_secret` is NO LONGER visible; profile renders `render_tab_section` output instead.
   - A **Regenerate** button appears.

Maps to: **FR-029, FR-030, FR-031, FR-032, FR-033, FR-035, Q2 admin client_id format, User Story 1**.

---

## Step 4 — Consent flow

1. Compute PKCE:
   ```sh
   VERIFIER=$(openssl rand -base64 32 | tr -d '=+/' | cut -c1-64)
   CHALLENGE=$(printf %s "$VERIFIER" | openssl dgst -binary -sha256 | openssl base64 | tr -d '=' | tr '/+' '_-')
   ```
2. Open in browser:
   ```
   https://<site>/authorize?response_type=code&client_id=<from-step-3>&redirect_uri=http://localhost:33333/callback&code_challenge=$CHALLENGE&code_challenge_method=S256&state=xyz&resource=https://<site>/wp-json/mcp/v1
   ```
3. Verify: consent screen renders OUTSIDE the WP admin frame (no admin bar, no theme header), shows the stub connector name + heading + the logged-in user's display name, Approve + Deny buttons, `_wpnonce` hidden input in page source.
4. Click **Deny**. Verify: browser redirects to `http://localhost:33333/callback?error=access_denied&state=xyz&iss=https://<site>` (open browser dev tools → Network to confirm even though localhost:33333 will fail to connect).
5. Repeat the URL; click **Approve**. Verify: browser redirects to `http://localhost:33333/callback?code=<64-hex>&state=xyz&iss=https://<site>`.
6. Verify a row exists in `wp_acrossai_mcp_oauth_auth_codes` with the SHA-256 of the returned code + the resource URL + PKCE challenge.

Maps to: **FR-004..FR-012, Q3 always-show consent, User Story 4**.

---

## Step 5 — Token exchange + audience binding

Using the code from Step 4:

```sh
curl -s -X POST https://<site>/token \
  -d "grant_type=authorization_code" \
  -d "code=<raw code from step 4>" \
  -d "client_id=<from step 3>" \
  -d "client_secret=<from step 3>" \
  -d "code_verifier=$VERIFIER" \
  -d "redirect_uri=http://localhost:33333/callback" | jq .
# Expected: JSON with access_token, token_type=Bearer, expires_in=3600, refresh_token, scope=mcp, resource=https://<site>/wp-json/mcp/v1
```

Verify the same code cannot be exchanged twice (replay defense):

```sh
# Repeat the exact same curl — expect:
# {"error":"invalid_grant","error_description":"..."}
```

Verify the token authenticates only against the resource it was issued for (Q1 audience-binding):

```sh
ACCESS_TOKEN=<from above>

# Correct audience — MCP tool call succeeds:
curl -s -H "Authorization: Bearer $ACCESS_TOKEN" https://<site>/wp-json/mcp/v1/{tool-call} | jq .
# Expected: HTTP 200 (or the tool's expected shape).

# Wrong audience — a different resource URL on same site → 401:
curl -sI -H "Authorization: Bearer $ACCESS_TOKEN" https://<site>/wp-json/wp/v2/users/me
# Expected: HTTP 401 (WordPress core rejects — TokenValidator did not authenticate).
```

Maps to: **FR-013..FR-018, FR-024, SC-006 replay defense, SC-007 audience enforcement, Q1**.

---

## Step 6 — Refresh token rotation

```sh
REFRESH_TOKEN=<from step 5>
curl -s -X POST https://<site>/token \
  -d "grant_type=refresh_token" \
  -d "refresh_token=$REFRESH_TOKEN" \
  -d "client_id=<from step 3>" \
  -d "client_secret=<from step 3>" | jq .
# Expected: NEW access_token + NEW refresh_token; same scope + resource.

# Verify old refresh token no longer works:
curl -s -X POST https://<site>/token \
  -d "grant_type=refresh_token" \
  -d "refresh_token=$REFRESH_TOKEN" \
  -d "client_id=..." | jq .
# Expected: {"error":"invalid_grant"}
```

Maps to: **FR-017, refresh rotation single-use**.

---

## Step 7 — Dynamic Client Registration

```sh
curl -s -X POST https://<site>/wp-json/acrossai-mcp-manager/v1/oauth/register \
  -H "Content-Type: application/json" \
  -d '{"redirect_uris":["https://client.example.com/callback"],"grant_types":["authorization_code","refresh_token"],"response_types":["code"],"token_endpoint_auth_method":"client_secret_post","client_name":"DCR Test"}' | jq .
# Expected: 201 Created, response with client_id (32 hex opaque, no server- prefix), client_secret (64 hex), fingerprint stored.

# Repeat the exact same POST body — expect the SAME client_id, no new secret issuance:
```

Verify a DB inspection:

```sh
wp db query "SELECT client_id, metadata_fingerprint FROM wp_acrossai_mcp_oauth_clients WHERE connector_slug=''"
# Expected: 1 row (dedupe worked — 2nd POST didn't insert a 2nd row).
```

Test invalid redirect URI (HTTP for non-loopback):

```sh
curl -s -X POST https://<site>/wp-json/acrossai-mcp-manager/v1/oauth/register \
  -H "Content-Type: application/json" \
  -d '{"redirect_uris":["http://not-loopback.example.com/cb"]}' | jq .
# Expected: 400 with error=invalid_redirect_uri, no row inserted.
```

Test rate-limit:

```sh
for i in $(seq 1 12); do
  curl -sI -X POST https://<site>/wp-json/acrossai-mcp-manager/v1/oauth/register \
    -H "Content-Type: application/json" \
    -d '{"redirect_uris":["https://example.com/cb"]}' | grep -E "^HTTP"
done
# Expected: first ~10 return 200/201, the 11th onward return 429.
```

Maps to: **FR-020..FR-023, FR-027, User Story 2, DCR dedupe, rate limit**.

---

## Step 8 — WP user deletion cascade (Q4)

1. Log in and complete a full OAuth flow → hold an access token bound to a specific `user_id`.
2. Delete that user via `wp user delete <id> --reassign=1` (or admin UI).
3. Verify:
   ```sh
   wp db query "SELECT COUNT(*) FROM wp_acrossai_mcp_oauth_tokens WHERE user_id=<id> AND revoked=0"
   # Expected: 0 (all revoked).
   
   wp db query "SELECT COUNT(*) FROM wp_acrossai_mcp_oauth_auth_codes WHERE user_id=<id>"
   # Expected: 0 (deleted).
   
   # Try the token — expect 401:
   curl -sI -H "Authorization: Bearer <the token>" https://<site>/wp-json/mcp/v1/{tool}
   # Expected: HTTP 401.
   ```

Maps to: **FR-042, Q4 cascade, edge case**.

---

## Step 9 — Cron cleanup

```sh
# Manually run the cron:
wp cron event run acrossai_mcp_manager_oauth_cleanup

# Verify expired auth codes + expired-revoked tokens are gone:
wp db query "SELECT COUNT(*) FROM wp_acrossai_mcp_oauth_auth_codes WHERE expires_at < NOW() OR used=1"
# Expected: 0.

wp db query "SELECT COUNT(*) FROM wp_acrossai_mcp_oauth_tokens WHERE expires_at < NOW() AND revoked=1"
# Expected: 0.
```

Maps to: **FR-037, FR-038, SC-008**.

---

## Step 10 — Uninstall behavior (opt-in gate)

1. Set flag OFF (default):
   ```sh
   wp option delete acrossai_mcp_uninstall_delete_data  # ensure default
   ```
2. Deactivate + delete plugin via WP admin.
3. Verify tables preserved:
   ```sh
   wp db query "SHOW TABLES LIKE 'wp_acrossai_mcp_oauth_%'"
   # Expected: 3 rows still present.
   wp cron event list | grep acrossai_mcp_manager_oauth_cleanup
   # Expected: 0 (deactivator cleared cron).
   ```
4. Reinstall + activate + set opt-in flag ON:
   ```sh
   wp option update acrossai_mcp_uninstall_delete_data 1
   ```
5. Uninstall via WP admin. Verify tables + options dropped:
   ```sh
   wp db query "SHOW TABLES LIKE 'wp_acrossai_mcp_oauth_%'"
   # Expected: 0.
   wp option get acrossai_mcp_oauth_clients_db_version
   # Expected: option not found.
   ```

Maps to: **FR-041, SC-009, DEC-UNINSTALL-OPT-IN-GATE**.

---

## Quality Gates

Run in the plugin root:

```sh
composer run phpcs           # Expected: zero errors.
composer run phpstan         # Expected: zero errors at level 8.
vendor/bin/phpunit tests/phpunit/OAuth/  # Expected: all pass.
```

Grep gates:

```sh
# No raw tokens/secrets/codes persisted:
grep -rEn "\\\$wpdb->insert.*'token'.*=>.*\\\$token\b" includes/OAuth/
# Expected: 0 matches. (Only hashes should be persisted.)

# BerlinDB Kern use collision:
grep -rEn "use BerlinDB\\\\Database\\\\Kern\\\\(Table|Schema|Query|Row)" includes/Database/OAuth*/
# Expected: 0 matches.

# DEC-BERLINDB-TABLE-REQUEST-BOOT co-commit (T5):
grep -c "OAuthClientsTable::instance()" includes/Activator.php
grep -c "OAuthClients\\\\Table::instance()" includes/Main.php
# Expected: 1 and 1.

# PKCE plain NOT accepted:
grep -rEn "'plain'" includes/OAuth/
# Expected: 0 matches OR only inside a rejection-error message string.

# S9 consent state — no trust in URL params:
grep -rEn '\\\$_GET\|\\\$_POST' includes/OAuth/AuthorizationController.php
# Expected: only inside handle_post's initial extraction; every subsequent auth-relevant check must re-load from OAuthClients DB row.
```

---

## Success Criteria — final tally

Once all 10 quickstart steps pass and all quality gates return zero errors:

- **SC-001**: 5-min journey (Steps 1-5 combined) ✓
- **SC-002**: PKCE plain rejected ✓ (grep gate + Step 4 negative test)
- **SC-003**: Rate limits enforced ✓ (Step 7)
- **SC-004**: Revoked token fails next request ✓ (Steps 5, 8)
- **SC-005**: DCR dedupe ✓ (Step 7)
- **SC-006**: Auth code replay defense ✓ (Step 5)
- **SC-007**: Audience binding ✓ (Step 5 audience negative test)
- **SC-008**: Cron cleanup ✓ (Step 9)
- **SC-009**: Uninstall opt-in ✓ (Step 10)
- **SC-010**: Zero built-in profiles ✓ (Step 3 empty-state check)
- **SC-011**: Zero measurable overhead on non-OAuth pages — measure via `Server-Timing` header or debug.log timestamps on a page load without Authorization header.
