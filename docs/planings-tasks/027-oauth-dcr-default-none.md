# Planning: OAuth DCR — default `token_endpoint_auth_method` to `none` for public+PKCE clients (Feature 027)

F021 (`docs/planings-tasks/021-oauth-2-1-implementation.md`, shipped as v1.6.0) delivered the plugin's OAuth 2.1 server: `/.well-known/oauth-authorization-server`, `/.well-known/oauth-protected-resource`, RFC 7591 Dynamic Client Registration at `POST /wp-json/acrossai-mcp-manager/v1/oauth/register`, authorization + PKCE S256 at `/authorize`, and code+refresh grants at `/token`.

A Claude.ai custom-connector connect against a production install (`https://acrossai.co`) fails with `Authorization with the MCP server failed … reference ofid_03a510582d1912c1`. The Claude.ai-side reference points at a server-side OAuth failure. Investigation showed:

- Discovery returns valid metadata (issuer `https://acrossai.co`; endpoints correct; `token_endpoint_auth_methods_supported = ["none","client_secret_post"]`).
- DCR persists the client with `token_endpoint_auth_method = "client_secret_post"` and a generated `client_secret_hash`.
- Authorize mints an auth code with valid PKCE S256 and marks it `used=1`.
- The tokens table has zero rows from that timestamp — token exchange failed after the atomic single-use consume.

Root cause is `ClientRegistrationController::handle_register()` at `includes/OAuth/ClientRegistrationController.php:310` defaulting `token_endpoint_auth_method` to `client_secret_post` when the DCR body omits it. Claude.ai (and every other modern MCP host — ChatGPT, Gemini connectors, Cursor, Cline) registers as a public+PKCE client: they either omit `token_endpoint_auth_method` or send `"none"`, and they never carry a `client_secret` through `/token`. When the server persists them as `client_secret_post`, the `TokenController::handle_authorization_code()` secret check at `includes/OAuth/TokenController.php:106-111` rejects the exchange with `invalid_client` — but the code has already been burned by `AuthCodeRepository::consume_atomic` at line 89, so the client sees the generic Claude.ai failure page and cannot retry.

RFC 8252 §8.4 recommends `none` for native/public clients using PKCE. Both allowed values (`none`, `client_secret_post`) are already advertised in the discovery metadata and accepted by the DCR allow-list at line 311 — this is purely a default change, no new endpoint surface, no schema change. Callers that want a confidential DCR client can still pass `token_endpoint_auth_method=client_secret_post` explicitly in the request body.

The change is **fully backwards-compatible**:

- Admin-generated confidential clients (`handle_admin_generate`, `ClientRegistrationController.php:187`) hardcode `client_secret_post` and are untouched.
- Existing DCR clients already in the database keep their persisted `token_endpoint_auth_method` value — nothing migrates them.
- Downstream `ClientRepository::create` at `includes/OAuth/Repositories/ClientRepository.php:42-47` already handles `null` `client_secret` for `none` clients; `handle_register` line 358 (`new_client_secret = 'none' === $token_endpoint_auth_method ? null : SecretsVault::random_token()`) already wires the two together.
- Existing tests remain green — `DCRRegisterFreshTest::test_valid_body_returns_201_with_opaque_client_id` explicitly sends `client_secret_post` and continues to pass.

---

## Speckit Workflow

```markdown
# 1. Branch
/speckit.git.feature "oauth-dcr-default-none"

# 2. Specify
/speckit.specify "In includes/OAuth/ClientRegistrationController.php inside
handle_register() at line 310, change the fallback of \$token_endpoint_auth_method
from 'client_secret_post' to 'none'. The isset()+is_string() guard on \$body
stays; only the third ternary branch changes. Add a docblock comment above the
ternary explaining that public+PKCE MCP clients (Claude.ai, ChatGPT, Cursor,
Cline) omit the field and never carry a client_secret through /token, so
'none' is the correct default per RFC 8252 §8.4; confidential-client callers
must pass 'client_secret_post' explicitly.

Add one PHPUnit case to tests/phpunit/OAuth/DCRRegisterFreshTest.php named
test_omitted_auth_method_defaults_to_none_public_client that dispatches
POST /acrossai-mcp-manager/v1/oauth/register with a body containing
redirect_uris=[https://claude.ai/api/mcp/auth_callback],
grant_types=[authorization_code, refresh_token], client_name=Claude, and
NO token_endpoint_auth_method key. Assert response status is 201, the response
body's token_endpoint_auth_method is 'none', and the body has NO client_secret
key.

Do NOT touch admin/Partials/, includes/Main.php, or any other OAuth file.
Do NOT change handle_admin_generate's hardcoded 'client_secret_post' at
ClientRegistrationController.php:187 — admin-issued clients remain
confidential. Do NOT touch DiscoveryController — the advertised
token_endpoint_auth_methods_supported list already includes both values.
Do NOT introduce a data migration for existing DCR client rows; a separate
SQL cleanup on the affected install purges the stale Claude.ai client
(58b6e1f7fe52c8ad608dbcbb1d334555) and its revoked tokens. Do NOT touch
tests/phpunit/OAuth/DCRDedupTest.php — the fingerprint semantics are
unchanged (token_endpoint_auth_method still participates)."
```

### Detailed Description for `/speckit.specify`

> **Before writing a single line of code, read and internalize the following documents in full:**
>
> 1. `AGENTS.md` — this plugin's singleton pattern, coding standards, and Before Commit Checklist.
> 2. `docs/planings-tasks/021-oauth-2-1-implementation.md` — F021 spec that shipped the OAuth 2.1 server. F027 is a one-line default fix inside F021's existing DCR endpoint; no new hook surface, no schema change, no new endpoint.
> 3. `includes/OAuth/ClientRegistrationController.php` — read `handle_register()` end-to-end (lines 258–410) plus the fingerprint computation at `compute_fingerprint()`. Confirm that:
>    - Line 311's allow-list already accepts `none`.
>    - Line 358 already null-guards `new_client_secret` when the method is `none`.
>    - Line 372 stores the persisted value verbatim; line 463's fingerprint hashes it. Changing the default therefore changes the fingerprint of every future omitted-field DCR body — clients that previously de-duped to the old `client_secret_post` row will now register a fresh `none` row instead. That's the intended behaviour.
> 4. `includes/OAuth/TokenController.php` — lines 106-111 (auth_code grant) and 203-208 (refresh grant) are the two secret-required paths that this change unblocks for public+PKCE clients.
> 5. `includes/OAuth/Repositories/ClientRepository.php:42-47` — confirms `client_secret_hash = null` is stored when the raw secret is null/empty. No orphan hash from the new default.
> 6. `tests/phpunit/OAuth/DCRRegisterFreshTest.php` — read the existing three cases; the new case slots between `test_public_client_none_auth_method_omits_secret` and `test_missing_redirect_uris_returns_400`.

## Manual verification

1. `composer run phpcs` → clean on touched files.
2. `composer run phpstan` → level 8 clean.
3. `vendor/bin/phpunit tests/phpunit/OAuth` → new case passes; existing cases stay green.
4. Post-merge deploy to `acrossai.co`. Run via the MCP `db-delete` ability:

    ```sql
    DELETE FROM wp_acrossai_mcp_oauth_tokens
      WHERE client_id IN ('58b6e1f7fe52c8ad608dbcbb1d334555', '8cebac3379c0fbd66a014c3a4e449ef0');
    DELETE FROM wp_acrossai_mcp_oauth_auth_codes
      WHERE client_id IN ('58b6e1f7fe52c8ad608dbcbb1d334555', '8cebac3379c0fbd66a014c3a4e449ef0');
    DELETE FROM wp_acrossai_mcp_oauth_clients
      WHERE client_id IN ('58b6e1f7fe52c8ad608dbcbb1d334555', '8cebac3379c0fbd66a014c3a4e449ef0');
    ```

5. Reconnect from Claude.ai → Settings → Connectors. Confirm via `db-select`:
    - New `wp_acrossai_mcp_oauth_clients` row has `token_endpoint_auth_method='none'`, `client_secret_hash` NULL.
    - New non-revoked access + refresh pair in `wp_acrossai_mcp_oauth_tokens` timestamped within the last minute.
    - `wp-content/debug.log` stops emitting `HttpTransport::check_permission … User ID 0` for the connector's requests.

## Out of scope (surfaced but not addressed here)

- `DiscoveryController::render_protected_resource_metadata` (line 79) defaults the `resource` value to `rest_url('mcp/v1')`, which is a 404. Only affects clients that hit `/.well-known/oauth-protected-resource` without a `?resource=` query param. Track as a separate ticket.
- Unrelated `PHP Deprecated` spam from `vendor/wordpress/mcp-adapter` in `debug.log` — upstream vendor noise.
