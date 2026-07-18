# Planning: OAuth `/token` accepts HTTP Basic auth + DCR-registered clients attributed to connector profiles (Feature 029)

F021 (`docs/planings-tasks/021-oauth-2-1-implementation.md`, shipped as v1.6.0 / consolidated in 0.1.0) delivered the OAuth 2.1 authorization server (discovery, DCR, `/authorize`, `/token`, bearer validation). F027 (`docs/planings-tasks/027-oauth-dcr-default-none.md`, shipped as v0.1.2) flipped the DCR default `token_endpoint_auth_method` from `client_secret_post` to `none` so modern MCP hosts (Claude.ai, ChatGPT, Cursor, Cline) — all public+PKCE clients that omit the field in DCR — would be persisted correctly and complete the code exchange on first attempt.

Feature 029 closes two follow-on gaps surfaced by real-world testing after v0.1.2 shipped:

1. **`/token` did not accept RFC 6749 §2.3.1 HTTP Basic authentication.** RFC 6749 §2.3.1 explicitly RECOMMENDS Basic auth (Authorization header carrying `base64(client_id:client_secret)`) as the primary transport for client credentials at the token endpoint. Some MCP hosts and generic OAuth clients use it. This plugin previously only read `client_id` / `client_secret` from the POST body — a spec conformance gap. When such a client hit `/token`, the endpoint returned `invalid_request` for the missing body-side `client_id` field even though the credentials were correctly presented in the Authorization header.

2. **DCR-registered clients had `connector_slug = ''`, bypassing F024 per-connector settings gating.** The F024 admin surface (introduced with F021) exposes per-connector enable/disable toggles and an admin-approval gate. Both are keyed on `connector_slug` — a value that admin-generated clients get from the `/oauth/generate-client` slug param, but that DCR-registered clients (opaque 32-hex `client_id` from `handle_register()`) previously received as a hardcoded empty string. Result: F024 gate resolution silently fell open for every Claude.ai / ChatGPT / Cursor / Cline connection.

Additionally, F029 adds a **defense-in-depth softening** to the token endpoint's `client_secret_post` verification: when a client is registered as `client_secret_post` but sends NO secret at exchange (neither in the Authorization header nor in the body), the endpoint falls through to PKCE-only verification instead of hard-rejecting with `invalid_client`. Rationale: modern MCP hosts occasionally register as `client_secret_post` (via pre-F027 DCR data or via admin generation) but then behave as public+PKCE at exchange, never carrying the secret. Rejecting them would break interop; PKCE + audience-bound + short-lived tokens still authenticate the exchange. This complements F027's source-of-truth default fix.

The changes are **fully backwards-compatible**:

- Clients that were correctly authenticating via body-only `client_id` / `client_secret` continue to work — the header-first-then-body resolution preserves body-only usage.
- Clients that DID send a `client_secret_post` secret are still verified (constant-time via `hash_equals` in `ClientRepository::verify_secret`) — the softening only affects the "no secret sent" branch.
- Existing DCR clients in the DB with `connector_slug = ''` continue to work; the attribution runs on NEW DCR registrations only.
- PKCE S256 remains mandatory (plain rejected regardless of client claim). RFC 8707 `resource` binding remains mandatory. Single-use auth codes (`AuthCodeRepository::consume_atomic` — B10) remain the atomicity guarantee.

---

## Speckit Workflow

```markdown
# 1. Branch
/speckit.git.feature "oauth-token-basic-auth-and-dcr-attribution"

# 2. Specify
/speckit.specify "In includes/OAuth/TokenController.php add a new private
static method read_client_credentials_from_header() that reads
\$_SERVER['HTTP_AUTHORIZATION'] (with CGI fallback to
\$_SERVER['REDIRECT_HTTP_AUTHORIZATION']), strips the 'Basic ' prefix
(case-insensitive stripos), base64_decodes with strict mode, and
explodes on the first colon into [\$client_id, \$client_secret]. Return
tuple of empty strings when the header is absent or malformed. Add
phpcs:ignore for base64_decode with RFC 6749 §2.3.1 justification.

In both handle_authorization_code() and handle_refresh_token(), resolve
\$client_id / \$client_secret from header-first-then-body BEFORE the
required-field validation. Remove client_id from the \$required array
in handle_authorization_code (guarded separately after credential
resolution to allow header-only presentation). Migrate every downstream
reference from \$body['client_id'] to the local \$client_id — that
includes hash_equals against \$row->client_id, ClientRepository::find_by_id,
AccessTokenRepository::issue, RefreshTokenRepository::issue, and the
acrossai_mcp_manager_oauth_token_issued action.

Soften the client_secret_post enforcement in BOTH grant handlers: change
the condition from 'if client method is client_secret_post, require
non-empty submitted secret AND verify it (else invalid_client)' to
'if client method is client_secret_post AND a secret was submitted,
verify it (else invalid_client)'. When the client method is
client_secret_post but NO secret was submitted (both header and body
empty for that field), fall through to PKCE-only verification for
authorization_code, or refresh-token-bound-to-client verification for
refresh_token.

In includes/OAuth/ClientRegistrationController.php::handle_register(),
immediately before ClientRepository::create() is called, walk every
profile returned by
ConnectorProfileRegistry::instance()->get_profiles() and call
\$profile->matches_dcr_client( \$client_name, \$redirect_uris ) on each.
First match wins: store \$profile->get_slug() into a local
\$attributed_slug; on no match, leave \$attributed_slug = ''. Pass
\$attributed_slug into ClientRepository::create() as connector_slug
in place of the hardcoded ''. Do NOT modify handle_admin_generate() —
its connector_slug is already set from the REST param.

Do NOT touch DiscoveryController, AuthorizationController,
TokenValidator, PKCE, Repositories, or any Database/OAuth* file.
Do NOT change the DCR request validation, the fingerprint dedup logic
at compute_fingerprint(), or the rate limiter. Do NOT modify the admin
generator route or its handler. Do NOT introduce a data migration for
existing DCR client rows with empty connector_slug (F016/D21 pattern —
fresh-install-only)."
```

### Detailed Description for `/speckit.specify`

> **Before writing a single line of code, read and internalize the following:**
>
> 1. `AGENTS.md` — plugin singleton pattern, A1 hook-in-Main-only, WPCS strict, PHPStan L8, Before-Commit Checklist.
> 2. `docs/planings-tasks/021-oauth-2-1-implementation.md` — F021 shipping spec. F029 extends the token endpoint's authentication surface and the DCR attribution surface without touching any other F021 module.
> 3. `docs/planings-tasks/027-oauth-dcr-default-none.md` — F027 (v0.1.2) source-of-truth default fix. F029 is the runtime-side complement.
> 4. `includes/OAuth/TokenController.php` — read `handle_authorization_code()` (lines 78–158), `handle_refresh_token()` (lines 165–256), and the RFC 6749 error-response helpers. Verify:
>    - Both grants currently read `client_id` / `client_secret` from `$body` only.
>    - Both grants hard-reject a missing `client_secret` when `token_endpoint_auth_method === 'client_secret_post'`.
>    - The atomic single-use auth code consumption happens BEFORE credential validation (`AuthCodeRepository::consume_atomic` at line 89). F029 preserves this ordering — do not move consume_atomic.
> 5. `includes/OAuth/ClientRegistrationController.php` — read `handle_register()` (lines 258–410) and `handle_admin_generate()` (lines 137–222). Confirm:
>    - `handle_register()` currently hardcodes `connector_slug => ''` at line 373.
>    - `handle_admin_generate()` populates `connector_slug` from the REST param — F029 leaves this path untouched.
> 6. `includes/OAuth/AuthorizationController.php:497-510` — the existing `infer_slug_from_dcr_client()` helper already runs the same walk-and-match at `/authorize` time to work around the empty-`connector_slug` gap. Post-F029, that helper can be simplified (its call site short-circuits to the persisted `connector_slug` when present) but the helper is NOT deleted by this feature — it stays for legacy rows with empty slugs.
> 7. `includes/Connectors/ConnectorProfileRegistry.php` — read `get_profiles()` and the `AbstractConnectorProfile::matches_dcr_client( string $name, array $redirect_uris ): bool` contract. F029's attribution walk trusts this contract; profile authors must implement `matches_dcr_client` correctly.

## Manual verification

1. `composer run phpcs` on touched files → zero warnings (base64_decode requires phpcs:ignore).
2. `composer run phpstan` → level 8 clean.
3. `composer run test --testsuite mcpclients` locally; CI runs the `oauth` integration suite.
4. Post-deploy `curl` smoke:
   - Header-only Basic auth: `curl -X POST -H 'Authorization: Basic $(echo -n client_id:secret | base64)' --data 'grant_type=authorization_code&code=…&code_verifier=…&redirect_uri=…' https://<site>/token` → 200 with token payload.
   - Body-only (existing shape): same POST minus the Authorization header, plus `&client_id=…&client_secret=…` → 200 (regression check).
   - PKCE-only (no secret sent, client is `client_secret_post`): POST with only `code + code_verifier + redirect_uri + client_id` → 200 (defense-in-depth check).
   - PKCE fail: same as above with a wrong `code_verifier` → 400 `invalid_grant PKCE verification failed`.
5. Verify DCR attribution: `curl -X POST -H 'Content-Type: application/json' --data '{"redirect_uris":["https://claude.ai/api/mcp/auth_callback"],"grant_types":["authorization_code","refresh_token"],"client_name":"Claude","token_endpoint_auth_method":"none"}' https://<site>/wp-json/acrossai-mcp-manager/v1/oauth/register` → 201; then `SELECT connector_slug FROM wp_acrossai_mcp_oauth_clients ORDER BY created_at DESC LIMIT 1` → should return the Claude profile's slug (whatever the ConnectorProfile registered with), NOT empty string.

## Out of scope

- **Migration of pre-F029 DCR rows with `connector_slug = ''`**: Per D21 (F016 fresh-install-only pattern), no in-plugin migration. Operators wanting to backfill can rerun `handle_register()` semantics via a WP-CLI script or a one-off SQL update.
- **`client_secret_basic` DCR advertisement**: The plugin's discovery metadata (`DiscoveryController.php:57`) advertises `token_endpoint_auth_methods_supported: ["none", "client_secret_post"]`. Adding `"client_secret_basic"` to that list would require a separate DCR validation change and is a follow-up feature — this PR only makes the `/token` endpoint tolerant of the header transport, not the DCR-side registration method.
- **AuthorizationController::infer_slug_from_dcr_client cleanup**: The helper stays functional for legacy rows with empty `connector_slug`. Simplifying it to short-circuit-on-persisted-slug is a small refactor for a follow-up feature.
