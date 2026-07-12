# Planning: OAuth 2.1 authorization server + connector profile framework (Feature 021)

Add a **provider-agnostic OAuth 2.1 + PKCE authorization server** and a **ConnectorProfile registry** to `acrossai-mcp-manager`, so browser-based LLM connectors (Claude first via a companion plugin; ChatGPT / Gemini / Copilot later) can authenticate against the MCP endpoints this plugin already ships. Zero modifications to `wordpress/mcp-adapter`, `wpboilerplate/wpb-access-control`, or `acrossai-core-abilities`. Zero new composer runtime dependencies — the OAuth server is hand-authored in pure PHP against WordPress core + PHP native crypto primitives, with `mehul0810/aculect-ai-companion`'s `src/Connectors/OAuth/` folder shape used as a structural reference only.

Three new BerlinDB tables (`OAuthClients`, `OAuthTokens`, `OAuthAuthCodes`) are added using the exact Schema/Query/Row/Table pattern established by Feature 011 (`MCPServer`, `CliAuthLog`, `MCPServerAbility`). One new built-in server tab (`AIConnectorsTab`) is added at priority 35 (between `ClientsTab` at 30 and `WpCliTab` at 40) via `Registry::all_tabs()`. One new public filter `acrossai_mcp_manager_connector_profiles` becomes the extension point that companion plugins register against.

The MCP adapter's `HttpTransport::check_permission()` (`vendor/wordpress/mcp-adapter/includes/Transport/HttpTransport.php:80-140`) already calls `current_user_can()`. The new `TokenValidator` hooks `determine_current_user` at priority 20 and populates the current user from the bearer token via `wp_set_current_user()`. Nothing about the adapter's permission callback changes.

Data model note: the existing `CliAuthLog` table already carries PKCE fields (`code_challenge` char(43), `code_challenge_method`) and the SHA-256 hash invariant (`auth_code_hash` char(64)) — Feature 021's `OAuthAuthCodes` table follows the same invariants byte-for-byte. Feature 021's `OAuthTokens` table follows Feature 011's `OAuthToken` hash-and-lookup pattern (an earlier version of a similar table was present pre-Feature-011 per that spec's reference to `wp_acrossai_mcp_oauth_tokens`, but is now gone — this feature reintroduces it under a schema that matches Anthropic's `resource` audience-binding + refresh rotation semantics).

The CLI OAuth flow at `includes/REST/CliController.php` (Application-Password issuance for CLI clients) is **not touched, not extended, not consolidated**. Different contract, different clients.

---

## Speckit Workflow

```markdown
# 1. Branch
/speckit.git.feature "oauth-2-1-implementation"

# 2. Specify
/speckit.specify "Add a provider-agnostic OAuth 2.1 + PKCE authorization
server to acrossai-mcp-manager exposing three domain-root endpoints
(/.well-known/oauth-authorization-server, /.well-known/oauth-protected-resource,
/authorize, /token) via add_rewrite_rule + parse_request following the existing
FrontendAuth pattern at public/Partials/FrontendAuth.php:42-102, plus one
REST-namespaced endpoint (POST /wp-json/acrossai-mcp-manager/v1/oauth/register)
for RFC 7591 Dynamic Client Registration. Store OAuth state in three new
BerlinDB tables under includes/Database/ (OAuthClients, OAuthTokens,
OAuthAuthCodes) using the Schema/Query/Row/Table pattern established by
Feature 011. Preserve every column-width invariant from CliAuthLog:
token_hash char(64), code_hash char(64), code_challenge char(43),
code_challenge_method default 'S256'. Add a public filter
acrossai_mcp_manager_connector_profiles to a new
includes/Connectors/ConnectorProfileRegistry singleton that returns
AbstractConnectorProfile instances contributed by companion plugins.
Add a new built-in server tab AIConnectorsTab at priority 35 to
admin/Partials/ServerTabs/, inserted into Registry::all_tabs() between
ClientsTab and WpCliTab. Do not fire the tabs filter to add it — it is a
built-in per Feature 019's extension contract. Hook determine_current_user
at priority 20 via includes/OAuth/TokenValidator with a static recursion
guard, reading Authorization: Bearer with REDIRECT_HTTP_AUTHORIZATION +
apache_request_headers + getallheaders fallbacks, SHA-256 hash lookup in
acrossai_mcp_oauth_tokens, calling wp_set_current_user on hit. Enforce
PKCE S256 mandatory on /authorize (plain rejected), atomic single-use on
auth codes via UPDATE ... WHERE used=0 pattern matching CliAuthLog's
redeem_atomic, refresh token rotation on by default at /token, RFC 8707
resource parameter audience-binding stored in tokens.resource column,
RFC 9207 authorization_response_iss_parameter_supported: true advertised
in metadata + emitted as iss on the callback redirect, RFC 6749 error
codes, 10-second SLA on authorization_code exchange, 30-second on
refresh_token exchange per Anthropic's connector spec (SEP-985 finalized
2025-07-16). Rate-limit /register at 10/IP/min and /authorize + /token
at 60/IP/min via get_transient / set_transient windows. Schedule a daily
cron acrossai_mcp_manager_oauth_cleanup — first cron in this plugin —
to purge expired tokens and consumed auth codes. Wire everything via the
existing Loader in Main::define_admin_hooks() and define_public_hooks(),
never in class constructors per Architecture Principle A1. Add a
self-contained consent HTML template at templates/oauth/consent.php with
wp_nonce_field / wp_verify_nonce protection. Extend Activator::activate()
with three additional Table::instance()->maybe_upgrade() calls plus one
wp_schedule_event. Extend Deactivator with wp_clear_scheduled_hook. Extend
uninstall.php to respect the existing acrossai_mcp_uninstall_delete_data
option — when true, drop the three new tables. No new composer runtime
dependencies. No modifications to wordpress/mcp-adapter or
wpboilerplate/wpb-access-control. No modifications to
includes/REST/CliController.php's Application-Password CLI OAuth flow —
that is a distinct contract. No plugin rename. No admin UI outside the
new tab. Public API contract: acrossai_mcp_manager_connector_profiles
filter and four new observability actions
(acrossai_mcp_manager_oauth_token_issued,
acrossai_mcp_manager_oauth_authorization_denied,
acrossai_mcp_manager_oauth_token_revoked,
acrossai_mcp_manager_oauth_cleanup) are permanent once shipped — never
remove invocations without a major version bump."
```

### Detailed Description for `/speckit.specify`

> **Before writing a single line of code, read and internalize all four of
> these governing documents in full:**
>
> 1. `AGENTS.md` — this plugin's singleton pattern, hook registration rules
>    (Architecture Principle A1: zero add_action/add_filter in class
>    constructors — all wiring via Loader), Before Commit Checklist.
> 2. Feature 011's pattern for BerlinDB tables:
>    `docs/planings-tasks/011-berlindb-migration.md` plus the four modules
>    it landed at `includes/Database/{MCPServer,CliAuthLog,MCPServerAbility}/*`.
>    Every new table in Feature 021 mirrors this shape — Schema/Query/Row/Table
>    + phantom-version guard + request-time boot in Main::bootstrap_database_tables().
> 3. Feature 019's per-server tabs contract:
>    `admin/Partials/ServerTabs/{Registry,AbstractServerTab,FilteredServerTab}.php`
>    plus `docs/extending-per-server-tabs.md` (if present). The new
>    AIConnectorsTab is a built-in tab inserted into `Registry::all_tabs()`
>    at priority 35, NOT contributed via the filter.
> 4. The MCP authorization spec (`modelcontextprotocol.io/specification/draft/basic/authorization`)
>    + SEP-985 (`modelcontextprotocol.io/seps/985-align-oauth-20-protected-resource-metadata-with-rf`)
>    + Anthropic's Claude Connector authentication doc
>    (`claude.com/docs/connectors/building/authentication`). Every RFC
>    citation in this document (RFC 6749, RFC 7636, RFC 7591, RFC 8414,
>    RFC 8707, RFC 9207, RFC 9728) is anchored to a specific spec obligation.
>
> Every decision — endpoint path, JSON field name, PKCE method, token
> lifetime, error code — must be justified against the above. If a choice
> is not explicitly covered, default to the strictest Anthropic requirement
> (e.g., S256 mandatory over plain).
>
> **Public API artifacts (permanent per Architecture Principle A1):**
>
> - Filter: `acrossai_mcp_manager_connector_profiles` — receives
>   `AbstractConnectorProfile[]`, returns same. Fires in
>   `ConnectorProfileRegistry::get_profiles()`.
> - Action: `acrossai_mcp_manager_oauth_token_issued` — args:
>   `$token_id, $client_id, $user_id, $connector_slug`.
> - Action: `acrossai_mcp_manager_oauth_authorization_denied` — args:
>   `$client_id, $redirect_uri, $reason`.
> - Action: `acrossai_mcp_manager_oauth_token_revoked` — args:
>   `$token_id, $reason`.
> - Action: `acrossai_mcp_manager_oauth_cleanup` — daily cron hook, no args.
> - Class contract: `AcrossAI_MCP_Manager\Includes\Connectors\AbstractConnectorProfile`
>   with abstract methods `get_slug`, `get_name`, `get_icon_url`,
>   `get_redirect_uri_whitelist`, `get_setup_instructions`,
>   `render_tab_section`, `get_consent_branding`.
>
> Pre-flight grep (records call sites in the base plugin whose behavior
> MUST NOT change after Feature 021):
> ```
> grep -rEn 'HttpTransport::check_permission|current_user_can|determine_current_user' \
>     --include='*.php' \
>     includes/ admin/ public/ vendor/wordpress/mcp-adapter/
> ```
> Every hit here that maps to `current_user_can()` MUST still resolve
> post-feature — Feature 021 only ADDS a `determine_current_user` filter;
> it does not modify the adapter's permission callback.
>
> Preserved column-width invariants (from Feature 011's memory —
> DO NOT NARROW):
>
> | Column | Table | Type | Length | Origin |
> | --- | --- | --- | --- | --- |
> | `auth_code_hash` | CliAuthLog | char | 64 | SHA-256 invariant, Feature 011 |
> | `code_challenge` | CliAuthLog | char | 43 | PKCE S256 invariant, Feature 011 |
> | `code_hash` | OAuthAuthCodes | char | 64 | **new — same SHA-256 invariant** |
> | `code_challenge` | OAuthAuthCodes | char | 43 | **new — same PKCE S256 invariant** |
> | `token_hash` | OAuthTokens | char | 64 | **new — same SHA-256 invariant** |
> | `client_secret_hash` | OAuthClients | char | 64 | **new — same SHA-256 invariant, NULLABLE (public clients omit)** |
>
> Preserved endpoint/route contract (data-preservation across future
> refactors — do not rename after ship):
>
> | Endpoint | Method | Path | Routing mechanism |
> | --- | --- | --- | --- |
> | AS Metadata | GET | `/.well-known/oauth-authorization-server` | `add_rewrite_rule` + `parse_request` (root) |
> | Protected Resource Metadata | GET | `/.well-known/oauth-protected-resource` | same |
> | Authorize | GET/POST | `/authorize` | same |
> | Token | POST | `/token` | same |
> | Dynamic Client Registration | POST | `/wp-json/acrossai-mcp-manager/v1/oauth/register` | `register_rest_route` |
> | Generate credentials (admin AJAX) | POST | `/wp-json/acrossai-mcp-manager/v1/oauth/generate-client` | `register_rest_route`, capability-gated, accepts `revoke_previous=true` for atomic revoke-and-issue |
>
> ---
>
> **TASK-1 — Add three BerlinDB tables under `includes/Database/`**
>
> Files:
> - `includes/Database/OAuthClients/{Schema,Query,Row,Table}.php` (4 new)
> - `includes/Database/OAuthTokens/{Schema,Query,Row,Table}.php` (4 new)
> - `includes/Database/OAuthAuthCodes/{Schema,Query,Row,Table}.php` (4 new)
>
> Follow the EXACT four-file pattern established by Feature 011 for
> `MCPServer/` and `CliAuthLog/`. Every Table subclass MUST include the
> phantom-version guard (DEC-BERLINDB-TABLE-REQUEST-BOOT / phantom-guard
> from Feature 011's DECISIONS.md):
>
> ```php
> public function maybe_upgrade(): void {
>     if ( ! $this->exists() ) {
>         delete_option( $this->db_version_key );
>     }
>     parent::maybe_upgrade();
> }
> ```
>
> Every Table also follows DEC-BERLINDB-SUBCLASS-NO-USE-COLLISION —
> extend via leading-`\` FQN, do NOT `use BerlinDB\Database\Kern\Table`.
>
> Column definitions:
>
> **`OAuthClients` — table `acrossai_mcp_oauth_clients`, $version = '1.0.0'**,
> $db_version_key = `acrossai_mcp_oauth_clients_db_version`:
>
> - `id` bigint(20) unsigned auto_increment primary
> - `client_id` varchar(64) — issued client identifier (public, safe to log)
> - `client_secret_hash` char(64) NULL — SHA-256 of raw secret; NULL when
>   `token_endpoint_auth_method='none'`
> - `client_name` varchar(255) — human-readable, set by DCR client_metadata
> - `redirect_uris` text — JSON-encoded array of allowed callback URLs
> - `grant_types` varchar(255) default `'authorization_code refresh_token'`
> - `token_endpoint_auth_method` varchar(32) default `'none'`
> - `connector_slug` varchar(64) default `''` — soft-link to the
>   ConnectorProfile that issued this client via the admin generator; `''`
>   when created via DCR
> - `metadata_fingerprint` char(64) default `''` — SHA-256 of a canonical
>   dumps of client_metadata + connector_slug; used by DCR to dedupe
>   idempotent re-registrations from the same LLM
> - `created_at` datetime `created=true`
>
> Indexes: primary(id), unique(client_id), key(connector_slug),
> key(metadata_fingerprint).
>
> **`OAuthTokens` — table `acrossai_mcp_oauth_tokens`, $version = '1.0.0'**,
> $db_version_key = `acrossai_mcp_oauth_tokens_db_version`:
>
> - `id` bigint(20) unsigned auto_increment primary
> - `token_hash` char(64) — SHA-256 of raw token; never store raw
> - `token_type` varchar(16) — `'access'` or `'refresh'`
> - `client_id` varchar(64) — FK-by-value to `OAuthClients.client_id`
> - `user_id` bigint(20) unsigned — WP user this token authenticates as
> - `scope` varchar(255) default `'mcp'`
> - `resource` varchar(500) default `''` — RFC 8707 audience URL from
>   the /authorize `resource` param; tokens are only valid against this URL
> - `expires_at` datetime — expiry timestamp
> - `revoked` tinyint(1) default 0
> - `created_at` datetime `created=true`
>
> Indexes: primary(id), unique(token_hash), key(client_id), key(user_id),
> key(expires_at), key(token_type).
>
> **`OAuthAuthCodes` — table `acrossai_mcp_oauth_auth_codes`, $version = '1.0.0'**,
> $db_version_key = `acrossai_mcp_oauth_auth_codes_db_version`:
>
> - `id` bigint(20) unsigned auto_increment primary
> - `code_hash` char(64) — SHA-256 of raw code
> - `client_id` varchar(64)
> - `user_id` bigint(20) unsigned
> - `redirect_uri` varchar(500) — must byte-match /authorize's redirect_uri
> - `code_challenge` char(43) — PKCE S256 invariant, matches CliAuthLog
> - `code_challenge_method` varchar(16) default `'S256'`
> - `scope` varchar(255) default `'mcp'`
> - `resource` varchar(500) default `''` — RFC 8707 audience carried
>   forward to the token that this code becomes
> - `used` tinyint(1) default 0
> - `expires_at` datetime
> - `created_at` datetime `created=true`
>
> Indexes: primary(id), unique(code_hash), key(expires_at).
>
> Query subclasses: singletons, `instance()`, extend
> `\AcrossAI_MCP_Manager\Includes\Database\BerlinDB_Query` (whatever the
> plugin's Feature 011 alias is). Add ONE bespoke method per Query for
> the atomic + cleanup pathways:
>
> - `OAuthAuthCodes\Query::consume_atomic( string $code_hash, string $now ): ?Row`
>   — UPDATE `used=1 WHERE code_hash=%s AND used=0 AND expires_at > %s`;
>   if `$wpdb->rows_affected === 1`, SELECT the row and return it; else
>   return null. Prevents replay under concurrent POSTs to /token.
>   Modelled on CliAuthLog::redeem_atomic (Feature 011).
> - `OAuthAuthCodes\Query::delete_expired( string $cutoff ): int` — bulk
>   delete rows with expires_at < cutoff OR used=1. Called by daily cron.
> - `OAuthTokens\Query::revoke_by_hash( string $token_hash ): bool` —
>   UPDATE `revoked=1 WHERE token_hash=%s AND revoked=0`; return true if
>   the row moved from 0 to 1.
> - `OAuthTokens\Query::delete_expired( string $cutoff ): int` — bulk
>   delete rows with (expires_at < cutoff AND revoked=1) OR expires_at <
>   (cutoff - 30 days).
> - `OAuthClients\Query::find_by_fingerprint( string $fingerprint ): ?Row`
>   — SELECT ... WHERE metadata_fingerprint=%s LIMIT 1; DCR uses this to
>   short-circuit repeat registrations.
>
> Row subclasses: public property list matches every column above +
> `to_array()` helper.
>
> ---
>
> **TASK-2 — Add OAuth core under `includes/OAuth/`**
>
> Files (all new, all singletons with private constructor, `instance()`
> factory, zero constructor hook wiring per A1):
> - `includes/OAuth/DiscoveryController.php`
> - `includes/OAuth/AuthorizationController.php`
> - `includes/OAuth/TokenController.php`
> - `includes/OAuth/ClientRegistrationController.php`
> - `includes/OAuth/TokenValidator.php`
> - `includes/OAuth/PKCE.php`
> - `includes/OAuth/Repositories/AccessTokenRepository.php`
> - `includes/OAuth/Repositories/RefreshTokenRepository.php`
> - `includes/OAuth/Repositories/AuthCodeRepository.php`
> - `includes/OAuth/Repositories/ClientRepository.php`
> - `includes/OAuth/Repositories/ScopeRepository.php`
> - `includes/OAuth/Security/RateLimiter.php`
> - `includes/OAuth/Security/SecretsVault.php`
>
> The Repository classes wrap the BerlinDB Query classes with
> feature-specific method names + hashing at the boundary. All raw-token
> generation goes through `SecretsVault::random_token(): string` (32
> random bytes → `bin2hex()`, returns 64-hex-char string). All at-rest
> lookup goes through `SecretsVault::hash( string $raw ): string`
> (`hash('sha256', $raw)`, returns 64-hex-char string). All secret
> comparison goes through `hash_equals()`.
>
> `PKCE::verify_s256( string $verifier, string $challenge ): bool`:
> ```php
> $computed = rtrim(
>     strtr(
>         base64_encode( hash( 'sha256', $verifier, true ) ),
>         '+/', '-_'
>     ),
>     '='
> );
> return hash_equals( $challenge, $computed );
> ```
>
> `DiscoveryController::render_authorization_server_metadata()` returns
> `wp_send_json()` with the RFC 8414 JSON body. Required fields:
>
> - `issuer` = `home_url()` (bare, no trailing slash)
> - `authorization_endpoint` = `home_url( '/authorize' )`
> - `token_endpoint` = `home_url( '/token' )`
> - `registration_endpoint` = `rest_url( 'acrossai-mcp-manager/v1/oauth/register' )`
> - `grant_types_supported` = `['authorization_code','refresh_token']`
> - `response_types_supported` = `['code']`
> - `token_endpoint_auth_methods_supported` = `['none','client_secret_post']`
> - `code_challenge_methods_supported` = `['S256']` (S256 ONLY per Anthropic)
> - `scopes_supported` = `['mcp']` (single scope; connector profiles
>   can extend via filter later)
> - `authorization_response_iss_parameter_supported` = `true` (RFC 9207)
> - `service_documentation` = `home_url( '/wp-admin/admin.php?page=acrossai_mcp_manager' )`
>
> HTTP response headers: `Content-Type: application/json`,
> `Cache-Control: public, max-age=3600`, `Access-Control-Allow-Origin: *`.
>
> `DiscoveryController::render_protected_resource_metadata()` returns
> RFC 9728 JSON. Required fields:
>
> - `resource` = the URL from the request's `resource` query param (if
>   present) or `rest_url( 'mcp/v1' )` (default MCP endpoint on this
>   server)
> - `authorization_servers` = `[ home_url() ]`
> - `bearer_methods_supported` = `['header']`
> - `scopes_supported` = `['mcp']`
> - Same cache/CORS headers as AS metadata.
>
> `AuthorizationController::handle_get( array $params ): void`:
>
> 1. Validate required params — `response_type=code`, `client_id`,
>    `redirect_uri`, `code_challenge`, `code_challenge_method=S256`,
>    `state` (recommended, not required by spec — accept without).
>    Reject `code_challenge_method != 'S256'` with a redirect containing
>    `error=invalid_request&error_description=PKCE+S256+required`.
> 2. Look up client via `ClientRepository::find_by_id( $client_id )`.
>    404-style redirect on miss.
> 3. Validate `redirect_uri` matches EITHER the client's registered
>    `redirect_uris` (exact byte match, JSON-decoded array from
>    `OAuthClients.redirect_uris`) OR the active `ConnectorProfile`'s
>    `get_redirect_uri_whitelist()` when `connector_slug` is set.
> 4. Validate `resource` param (RFC 8707) — MUST be present per MCP
>    spec, MUST be a valid HTTPS URL that lives on this WordPress site
>    OR loopback. Redirect with `error=invalid_target` on failure.
> 5. If `is_user_logged_in()` is false → `wp_redirect( wp_login_url(
>    $current_authorize_url ) )` and exit.
> 6. Render `templates/oauth/consent.php` with the profile's consent
>    branding + the WP user's display name + a nonce field.
>
> `AuthorizationController::handle_post( array $params ): void`:
>
> 1. `wp_verify_nonce( $_POST['_wpnonce'], 'acrossai_mcp_manager_oauth_authorize' )`
>    or 403.
> 2. Re-validate all params from GET as if fresh (defense in depth).
> 3. If `authorize_action === 'deny'` → redirect to `redirect_uri`
>    with `error=access_denied` + `state` + `iss=home_url()`. Also
>    fire `do_action( 'acrossai_mcp_manager_oauth_authorization_denied',
>    $client_id, $redirect_uri, 'user_denied' )`.
> 4. Else generate a raw auth code via `SecretsVault::random_token()`,
>    persist via `AuthCodeRepository::create()` with SHA-256 hash +
>    challenge + resource + user_id + client_id + redirect_uri +
>    scope, TTL = 600 seconds (matches CliAuthLog's auth code TTL).
> 5. Redirect to `redirect_uri` with `code=<raw>&state=<state>&iss=<issuer>`.
>
> `TokenController::handle_authorization_code( array $params ): array`:
>
> 1. Required params: `grant_type=authorization_code`, `code`,
>    `client_id`, `code_verifier`, `redirect_uri`. Optional: `client_secret`.
> 2. `AuthCodeRepository::consume_atomic( SecretsVault::hash( $code ), $now )`
>    returns `?Row` — null → RFC 6749 `invalid_grant`.
> 3. `hash_equals( $row->client_id, $params['client_id'] )` or
>    `invalid_grant`.
> 4. `hash_equals( $row->redirect_uri, $params['redirect_uri'] )` or
>    `invalid_grant`.
> 5. Look up client. If `token_endpoint_auth_method='client_secret_post'`,
>    require `client_secret` and `hash_equals( $client->client_secret_hash,
>    SecretsVault::hash( $client_secret ) )` or `invalid_client`.
> 6. `PKCE::verify_s256( $code_verifier, $row->code_challenge )` or
>    `invalid_grant`.
> 7. Issue access token (TTL 3600 s) + refresh token (TTL 2592000 s)
>    via `AccessTokenRepository::issue()` + `RefreshTokenRepository::issue()`.
>    Both share the auth code's `resource` and `scope`.
> 8. `do_action( 'acrossai_mcp_manager_oauth_token_issued', $access_id,
>    $client_id, $user_id, $client->connector_slug )`.
> 9. Return JSON: `access_token`, `token_type='Bearer'`, `expires_in=3600`,
>    `refresh_token`, `scope`, `resource`. Headers:
>    `Cache-Control: no-store, no-cache, must-revalidate`, `Pragma: no-cache`,
>    `Content-Type: application/json`.
>
> `TokenController::handle_refresh_token( array $params ): array`:
>
> 1. Required: `grant_type=refresh_token`, `refresh_token`, `client_id`.
>    Optional: `client_secret`.
> 2. Look up refresh token via
>    `RefreshTokenRepository::find_by_hash( SecretsVault::hash( $refresh_token ) )`.
>    Missing / expired / revoked → `invalid_grant`.
> 3. `hash_equals` client_id, client_secret check (same as above).
> 4. Rotate: `RefreshTokenRepository::revoke( $old_id )` then issue new
>    pair. Same resource + scope carried forward.
> 5. `do_action( 'acrossai_mcp_manager_oauth_token_revoked', $old_id,
>    'refresh_rotation' )` + `do_action( 'acrossai_mcp_manager_oauth_token_issued',
>    ... )`.
>
> `ClientRegistrationController::handle_register( WP_REST_Request $request ): WP_REST_Response`:
>
> 1. Method must be POST, Content-Type `application/json`. Reject otherwise
>    with 400 + `error=invalid_request`.
> 2. Rate-limit: `RateLimiter::check_ip( 'oauth_register', 10, 60 )` — 429
>    on exceeded.
> 3. Parse client_metadata from JSON body. Validate `redirect_uris` — every
>    URI must be either loopback (`127.0.0.1`, `localhost`, `::1` on any
>    port) OR HTTPS. Reject invalid with 400.
> 4. Compute `metadata_fingerprint = SHA-256( canonical_json( {
>    redirect_uris, grant_types, response_types, token_endpoint_auth_method,
>    connector_slug?} ) )`.
> 5. `ClientRepository::find_by_fingerprint( $fingerprint )` — if a client
>    already exists with this fingerprint, RETURN THAT CLIENT'S metadata
>    without issuing a new secret (idempotent DCR — aculect's pattern).
>    Fire no observability event on a dedupe hit.
> 6. Else generate `client_id` = `bin2hex( random_bytes(16) )` (32-hex).
>    If `token_endpoint_auth_method !== 'none'`, generate `client_secret`
>    = `bin2hex( random_bytes(32) )` (64-hex) and hash to `client_secret_hash`.
> 7. Insert via `ClientRepository::create()`. Return JSON with `client_id`,
>    `client_secret` (raw — only returnable here, never again),
>    `client_id_issued_at`, `client_secret_expires_at=0` (never expires),
>    plus every echoed field from client_metadata.
> 8. Response headers: `Cache-Control: no-store`, `Content-Type: application/json`.
>
> `TokenValidator::authenticate( int|false $user_id ): int|false`:
>
> ```php
> public function authenticate( $user_id ) {
>     static $resolving = false;
>     if ( $resolving ) {
>         return $user_id;
>     }
>     if ( ! empty( $user_id ) ) {
>         return $user_id;
>     }
>     $header = $this->read_authorization_header();
>     if ( 0 !== stripos( $header, 'Bearer ' ) ) {
>         return $user_id;
>     }
>     $token = trim( substr( $header, 7 ) );
>     if ( '' === $token || strlen( $token ) > 128 ) {
>         return $user_id;
>     }
>     $resolving = true;
>     $row = AccessTokenRepository::instance()->find_by_hash(
>         SecretsVault::hash( $token )
>     );
>     $resolving = false;
>     if ( null === $row || $row->revoked || $row->expires_at <= current_time( 'mysql', 1 ) ) {
>         return $user_id;
>     }
>     // TODO(RFC 8707 audience-binding): if resource is set, verify the
>     // incoming request URL matches. Deferred to a follow-up task; v1
>     // trusts the resource carried on the token.
>     return (int) $row->user_id;
> }
> ```
>
> `read_authorization_header` tries `$_SERVER['HTTP_AUTHORIZATION']`,
> then `REDIRECT_HTTP_AUTHORIZATION`, then `apache_request_headers()`,
> then `getallheaders()`.
>
> ---
>
> **TASK-3 — Add ConnectorProfile framework under `includes/Connectors/`**
>
> Files (new):
> - `includes/Connectors/AbstractConnectorProfile.php`
> - `includes/Connectors/ConnectorProfileRegistry.php`
>
> Abstract class contract (declare `abstract` methods; provide default
> implementations for optional lifecycle methods). Do NOT use an
> `interface` — abstract class allows shared helpers and default
> implementations, and matches Feature 019's `AbstractServerTab` pattern.
>
> ```php
> abstract public function get_slug(): string;
> abstract public function get_name(): string;
> abstract public function get_icon_url(): string;
> abstract public function get_redirect_uri_whitelist(): array;
> abstract public function get_setup_instructions(
>     array $server,
>     string $client_id,
>     string $client_secret
> ): string;
> abstract public function render_tab_section( array $server ): void;
>
> /**
>  * Consent-screen branding. Default returns a neutral heading; profiles
>  * override to show their brand.
>  *
>  * @return array{heading:string,subtitle:string,permissions_bullets:string[]}
>  */
> public function get_consent_branding(): array {
>     return array(
>         'heading'              => sprintf(
>             /* translators: %s: connector display name */
>             __( '%s wants to connect to your site', 'acrossai-mcp-manager' ),
>             $this->get_name()
>         ),
>         'subtitle'             => __(
>             'This will allow the application to access the MCP tools you have exposed on this server.',
>             'acrossai-mcp-manager'
>         ),
>         'permissions_bullets'  => array(),
>     );
> }
> ```
>
> Registry singleton. `get_profiles()` fires the filter once per request
> (memoized) and returns a deduped, slug-sorted `AbstractConnectorProfile[]`.
> `get_profile( string $slug ): ?AbstractConnectorProfile` returns the
> matching instance or null. `register_profile()` is intentionally
> absent — the ONLY registration path is the filter.
>
> ---
>
> **TASK-4 — Add built-in server tab `AIConnectorsTab`**
>
> Files:
> - `admin/Partials/ServerTabs/AIConnectorsTab.php` (new)
> - `admin/Partials/ServerTabs/Registry.php` (modify `all_tabs()` at
>   lines 108-121 to insert `new AIConnectorsTab()` at priority 35)
>
> Extend `AbstractServerTab`. `slug()` returns `'ai-connectors'`,
> `label()` returns `__( 'AI Connectors', 'acrossai-mcp-manager' )`,
> `priority()` returns 35. `render_body( array $server )` iterates
> `ConnectorProfileRegistry::instance()->get_profiles()`. For each
> profile:
>
> 1. Look up existing `OAuthClients` row where `connector_slug =
>    $profile->get_slug() AND client_id LIKE 'server-{server_id}-%'`
>    (server-scoped client ID convention).
> 2. If none exists, render a "Generate credentials" button that POSTs
>    to `/wp-json/acrossai-mcp-manager/v1/oauth/generate-client?server={id}&connector_slug={slug}`.
> 3. If one exists, call `$profile->render_tab_section( $server_row )`
>    passing the row through so the profile can show its own instructions
>    with the actual client credentials.
>
> If zero profiles registered, render an empty-state notice pointing at
> the docs page for installing a connector profile companion plugin
> (e.g. `acrossai-claude-connectors`).
>
> The "generate credentials" REST route lives in
> `ClientRegistrationController::register_admin_generate_route()` and is
> `permission_callback` = `current_user_can( 'manage_options' )` +
> nonce-checked. It creates a `client_secret_post` client with
> `connector_slug` set + `redirect_uris` = the profile's whitelist +
> generates a fresh `client_secret`. Returns JSON `{client_id, client_secret}`
> ONCE — subsequent GETs to the tab show a "regenerate" button.
>
> **Atomic revoke-and-issue (required for Feature 002 SEC-002)**: the
> endpoint MUST accept an optional `revoke_previous: bool` request
> parameter (default `false`). When `true`:
>
> 1. Look up existing `OAuthClients` row where
>    `connector_slug = $slug AND server_id = $server_id` (or however
>    server-scoping is expressed on this endpoint).
> 2. In a single `$wpdb` transaction (`START TRANSACTION` / `COMMIT` /
>    `ROLLBACK` on any error):
>    a. `UPDATE acrossai_mcp_oauth_tokens SET revoked = 1 WHERE
>       client_id = <old client_id> AND revoked = 0` — revokes ALL
>       access + refresh tokens issued to the old client.
>    b. `DELETE FROM acrossai_mcp_oauth_clients WHERE id = <old row id>`.
>    c. `INSERT` the new client row + return the new `client_id +
>       client_secret` in the response body.
> 3. Fire `do_action( 'acrossai_mcp_manager_oauth_token_revoked', $token_id,
>    'client_regenerated' )` for each revoked token (batch or per-row —
>    per Feature 021's observability contract).
>
> When `false` (default), behavior is unchanged: idempotent-issue-first
> semantics. Feature 002's "Regenerate" button always sends
> `revoke_previous=true`; Feature 002's initial "Generate credentials"
> button omits the flag or sends `false`.
>
> ---
>
> **TASK-5 — Wire hooks in Main.php**
>
> Files: `includes/Main.php`
>
> Extend `bootstrap_database_tables()` (lines 204-209 in current
> post-Feature-011 state) to instantiate the three new tables:
>
> ```php
> \AcrossAI_MCP_Manager\Includes\Database\OAuthClients\Table::instance();
> \AcrossAI_MCP_Manager\Includes\Database\OAuthTokens\Table::instance();
> \AcrossAI_MCP_Manager\Includes\Database\OAuthAuthCodes\Table::instance();
> ```
>
> Extend `define_admin_hooks()` (lines 274-433):
>
> ```php
> $client_reg = \AcrossAI_MCP_Manager\Includes\OAuth\ClientRegistrationController::instance();
> $this->loader->add_action( 'rest_api_init', $client_reg, 'register_dcr_route' );
> $this->loader->add_action( 'rest_api_init', $client_reg, 'register_admin_generate_route' );
>
> $connector_registry = \AcrossAI_MCP_Manager\Includes\Connectors\ConnectorProfileRegistry::instance();
> $this->loader->add_action( 'init', $connector_registry, 'boot', 5 );
> ```
>
> Extend `define_public_hooks()` (lines 442-475):
>
> ```php
> // OAuth root routing (follows FrontendAuth's pattern)
> $oauth_router = \AcrossAI_MCP_Manager\Includes\OAuth\OAuthRouter::instance();
> $this->loader->add_action( 'init', $oauth_router, 'register_rewrite_rules' );
> $this->loader->add_filter( 'query_vars', $oauth_router, 'add_query_vars' );
> $this->loader->add_action( 'parse_request', $oauth_router, 'dispatch' );
>
> // Bearer bridge
> $token_validator = \AcrossAI_MCP_Manager\Includes\OAuth\TokenValidator::instance();
> $this->loader->add_filter( 'determine_current_user', $token_validator, 'authenticate', 20 );
>
> // Cron cleanup
> $cleanup = \AcrossAI_MCP_Manager\Includes\OAuth\Cleanup::instance();
> $this->loader->add_action( 'acrossai_mcp_manager_oauth_cleanup', $cleanup, 'run' );
> ```
>
> Add a new small class `includes/OAuth/OAuthRouter.php` that owns the
> rewrite rules and parse_request dispatch. Rewrite rules
> (`add_rewrite_rule` in `register_rewrite_rules()`):
>
> ```php
> add_rewrite_rule( '^\.well-known/oauth-authorization-server/?$', 'index.php?acrossai_mcp_oauth=as_metadata', 'top' );
> add_rewrite_rule( '^\.well-known/oauth-protected-resource/?$',   'index.php?acrossai_mcp_oauth=pr_metadata', 'top' );
> add_rewrite_rule( '^authorize/?$', 'index.php?acrossai_mcp_oauth=authorize', 'top' );
> add_rewrite_rule( '^token/?$',     'index.php?acrossai_mcp_oauth=token', 'top' );
> ```
>
> Query var: `acrossai_mcp_oauth`. `dispatch()` reads
> `get_query_var('acrossai_mcp_oauth')` and delegates to
> `DiscoveryController`, `AuthorizationController`, or `TokenController`
> accordingly.
>
> `Cleanup::run()`:
>
> ```php
> $cutoff = current_time( 'mysql', 1 );
> AuthCodesQuery::instance()->delete_expired( $cutoff );
> TokensQuery::instance()->delete_expired( $cutoff );
> ```
>
> ---
>
> **TASK-6 — Activator + Deactivator + uninstall**
>
> Files:
> - `includes/Activator.php`
> - `includes/Deactivator.php`
> - `uninstall.php`
>
> Activator: after the three existing `MCPServerTable`, `CliAuthLogTable`,
> `MCPServerAbilityTable` `maybe_upgrade` calls (lines 39, 41, 44), add:
>
> ```php
> OAuthClientsTable::instance()->maybe_upgrade();
> OAuthTokensTable::instance()->maybe_upgrade();
> OAuthAuthCodesTable::instance()->maybe_upgrade();
>
> if ( ! wp_next_scheduled( 'acrossai_mcp_manager_oauth_cleanup' ) ) {
>     wp_schedule_event( time(), 'daily', 'acrossai_mcp_manager_oauth_cleanup' );
> }
> ```
>
> Deactivator: add
> `wp_clear_scheduled_hook( 'acrossai_mcp_manager_oauth_cleanup' )`.
> Do NOT drop tables. Do NOT delete `_db_version` options.
>
> uninstall.php: respect the existing
> `acrossai_mcp_uninstall_delete_data` option (line 33). When true, add
> `DROP TABLE` statements for the three new tables + delete the three
> new `_db_version` options + `wp_clear_scheduled_hook`.
>
> ---
>
> **TASK-7 — Add consent template**
>
> Files: `templates/oauth/consent.php` (new)
>
> Self-contained HTML — no admin frame, no theme wrapper. `<!doctype html>`
> + inline `<style>` + one `<form method="post">`. Structure:
>
> - `<meta name="robots" content="noindex, nofollow">`
> - `<title>` = `sprintf( __( 'Authorize — %s', 'acrossai-mcp-manager' ),
>   get_bloginfo( 'name' ) )`
> - Header card: site icon (from `$profile->get_icon_url()`), connector
>   heading (from `get_consent_branding()['heading']`), subtitle,
>   permissions bullet list.
> - "Signed in as {display_name} on {site_name}" line.
> - `<form method="post" action="{current_authorize_url}">`
>   - `wp_nonce_field( 'acrossai_mcp_manager_oauth_authorize' )`
>   - Hidden inputs echoing every /authorize param (client_id,
>     redirect_uri, code_challenge, code_challenge_method, state, scope,
>     resource).
>   - Two buttons: `<button name="authorize_action" value="deny">Deny</button>`
>     and `<button name="authorize_action" value="approve">Approve</button>`.
> - Footer: "Powered by AcrossAI MCP Manager".
>
> Rendered by `AuthorizationController::render_consent()` — the OAuth
> route is a DEDICATED page rendered outside WP admin, so no
> `admin_body_class`, no admin bar, no theme header. Use
> `require_once` on the template path.
>
> ---
>
> **TASK-8 — Extend acrossai-mcp-manager.php + readme.txt**
>
> Files:
> - `acrossai-mcp-manager.php`
> - `readme.txt`
> - `composer.json` (version bump only — no runtime dep changes)
>
> Bump the plugin `Version` header from `0.0.9` to `0.1.0` (minor bump —
> new public API surface).
>
> readme.txt Unreleased changelog:
>
> ```
> = 0.1.0 =
> * Added OAuth 2.1 + PKCE authorization server for browser-based MCP
>   connectors (Claude, ChatGPT, Gemini, GitHub Copilot). Exposes
>   .well-known discovery, /authorize with consent, /token with
>   authorization_code + refresh_token grants, and /register RFC 7591
>   Dynamic Client Registration.
> * Added ConnectorProfile registry (`acrossai_mcp_manager_connector_profiles`
>   filter) so per-LLM companion plugins can plug in without base plugin
>   changes.
> * Added AI Connectors server-edit tab showing connector setup + credentials.
> * Zero new runtime dependencies. PHP 8.1 minimum unchanged.
> ```
>
> composer.json: no runtime dep changes. Bump version if the composer.json
> has a `version` field (many WP plugins do not — check first). Run
> `composer dump-autoload` after edits per this plugin's B1 pattern.
>
> ---
>
> **TASK-9 — Add documentation**
>
> Files:
> - `docs/extending-connector-profiles.md` (new — parallels
>   `docs/extending-per-server-tabs.md` from Feature 019)
> - `docs/planings-tasks/README.md` (append this feature's row to the
>   index table)
>
> `extending-connector-profiles.md` covers:
>
> - How to write a companion plugin (link to `acrossai-claude-connectors`
>   as the reference).
> - The `AbstractConnectorProfile` contract with a worked example.
> - The `acrossai_mcp_manager_connector_profiles` filter signature.
> - Redirect-URI whitelist rules (RFC 8707 audience-binding
>   implications).
> - Consent branding customization.
>
> ---
>
> **TASK-10 — Add unit tests under `tests/phpunit/OAuth/`**
>
> Files (new):
> - `tests/phpunit/OAuth/PKCEVerifyTest.php`
> - `tests/phpunit/OAuth/TokenValidatorTest.php`
> - `tests/phpunit/OAuth/ConnectorProfileRegistryTest.php`
> - `tests/phpunit/OAuth/RateLimiterTest.php`
> - `tests/phpunit/OAuth/AuthCodeConsumeAtomicTest.php`
> - `tests/phpunit/OAuth/DiscoveryMetadataTest.php`
>
> Coverage minimums:
>
> - `PKCEVerifyTest`: S256 round-trip PASS with known-good vectors from
>   RFC 7636 Appendix B; `plain` challenge_method rejected; empty verifier
>   rejected; verifier > 128 chars rejected.
> - `TokenValidatorTest`: recursion guard (static `$resolving` prevents
>   re-entry when `current_user_can` inside token lookup fires our
>   filter again); no-token pass-through returns original `$user_id`;
>   valid token returns mapped user_id; revoked token pass-through;
>   expired token pass-through; malformed header pass-through.
> - `ConnectorProfileRegistryTest`: filter contribution registers a
>   profile; two profiles with the same slug — the later one wins with
>   a `_doing_it_wrong` notice; empty filter output returns empty array;
>   memoization — filter fires exactly once per request.
> - `RateLimiterTest`: 10 requests in 60s window all pass; 11th returns
>   429; window rolls after 60s (fake time via
>   `\WP_Mock\Functions\Handler` or transient injection).
> - `AuthCodeConsumeAtomicTest`: mirrors Feature 011's
>   `AtomicCasTest` — (A) first `consume_atomic` returns non-null +
>   row.used=1 in DB; (B) second call returns null; (C) `$wpdb->last_query`
>   matches `UPDATE .* SET used = 1 .* WHERE code_hash = %s AND used = 0 AND expires_at > %s`.
> - `DiscoveryMetadataTest`: `render_authorization_server_metadata` JSON
>   includes every field enumerated in the spec table above with correct
>   types. `render_protected_resource_metadata` JSON echoes the
>   `resource` param verbatim + returns the plugin's issuer.
>
> ---
>
> **TASK-11 — Memory hygiene + changelog**
>
> Files:
> - `docs/memory/DECISIONS.md`
> - `docs/memory/WORKLOG.md`
> - `docs/memory/INDEX.md`
> - `docs/memory/ARCHITECTURE.md`
>
> Add the following Active decisions (do NOT supersede any Feature 011
> phantom-guard or column-width invariants — they are reused verbatim):
>
> - `DEC-OAUTH-NO-LIBRARY (Active — Feature 021)`: The OAuth 2.1 server
>   is hand-authored against WordPress core + PHP native crypto. Rejected
>   candidates and reasoning are recorded in the Sourcing decisions
>   section below. Reference the specific PHP version constraint that
>   ruled out league/oauth2-server v9 (PHP 8.2+ vs plugin's 8.1 floor).
> - `DEC-OAUTH-CONNECTOR-PROFILE-FILTER (Active — Feature 021)`:
>   `acrossai_mcp_manager_connector_profiles` is a permanent public API.
>   Once shipped, its invocation is preserved through any refactor.
> - `DEC-OAUTH-BUILTIN-TAB-NOT-FILTER (Active — Feature 021)`: The
>   `AIConnectorsTab` is a built-in tab in `Registry::all_tabs()`, NOT
>   a filter contribution. Rationale: it's owned by the base plugin, not
>   a third-party contribution — Feature 019's filter is for third-party
>   contributions.
> - `DEC-OAUTH-PKCE-S256-MANDATORY (Active — Feature 021)`:
>   `code_challenge_method` MUST be `S256`. `plain` is rejected at
>   /authorize regardless of what the metadata document advertises. Anthropic's
>   connector spec requires S256; there is no compatibility reason to
>   accept `plain`.
> - `DEC-OAUTH-CLI-FLOW-SEPARATE (Active — Feature 021)`: The existing
>   `CliController` at `includes/REST/CliController.php` (Application
>   Password issuance for CLI clients) is NOT reused, NOT extended, NOT
>   consolidated with the new OAuth 2.1 server. Different contract.
>   Merging is out of scope.
>
> Add WORKLOG entry for Feature 021 milestone (Why durable / Future
> mistake prevented / Evidence / Where to look). Highlight the durable
> lesson: **a WordPress plugin can host an OAuth 2.1 + PKCE server in
> ~1,500-2,000 LOC of pure PHP against WP core APIs — no external OAuth
> library required for the browser-connector flow.**
>
> INDEX.md — append rows for the four new Active decisions + the WORKLOG
> entry.
>
> ARCHITECTURE.md — capture the tab-vs-filter distinction as a durable
> pattern under the existing tabs section.
>
> ---
>
> **CONSTRAINTS**
>
> - **Do not modify `vendor/wordpress/mcp-adapter/`.** The adapter's
>   `HttpTransport::check_permission()` is the ground truth for whether
>   an MCP request is allowed. We only make the user known to WP; we
>   don't touch the adapter's permission wiring.
> - **Do not modify `includes/REST/CliController.php`.** The CLI OAuth
>   flow that emits Application Passwords is a separate contract from the
>   OAuth 2.1 browser flow this feature adds.
> - **Do not modify `includes/AccessControl/`.** Per-ability capability
>   checks fire against `current_user_can()` which our TokenValidator
>   populates upstream — no changes needed there.
> - **Do not modify `admin/Partials/ServerTabs/Registry.php`'s filter
>   line (143).** The `acrossai_mcp_manager_server_tabs` filter for
>   third-party contributions stays intact. Our new tab is inserted in
>   `all_tabs()` (lines 108-121) as a built-in.
> - **Do not narrow the column widths.** `token_hash char(64)`,
>   `code_hash char(64)`, `code_challenge char(43)`, `client_secret_hash
>   char(64) NULL` — every width is a cryptographic invariant. Any
>   narrowing on a production install fires `ALTER TABLE` and truncates
>   real tokens.
> - **Do not narrow index names.** BerlinDB uses `name` for diff-matching
>   (per Feature 011's memory); index-name renames equal drop + create.
> - **Do not skip the phantom-version guard on any of the three new
>   Tables.** Feature 011 established this as canonical for every
>   BerlinDB-backed table this plugin adds.
> - **Do not add data migration.** These are new tables. `dbDelta` /
>   BerlinDB's diff engine handles the empty-table case natively.
> - **Do not add composer runtime dependencies.** The plugin's dep
>   footprint stays at berlindb/core, jetpack-autoloader,
>   wpboilerplate/wpb-access-control, acrossai-co/main-menu, and
>   wordpress/mcp-adapter. No league/oauth2-server, no PSR-7 bridge, no
>   phpseclib, no JWT library.
> - **Do not enqueue admin scripts on the consent page.** The consent
>   template renders outside WP admin — self-contained inline CSS only.
> - **Do not persist raw tokens, raw secrets, or raw codes at rest.** Only
>   SHA-256 hashes. `hash_equals` on every comparison.
> - **Do not accept `code_challenge_method != 'S256'`.** Not even `plain`
>   with a filterable escape hatch. S256 is mandatory.
> - **Do not accept HTTP redirect_uris for non-loopback clients.** HTTPS
>   or `127.0.0.1` / `localhost` / `::1` on any port. Documented in
>   `ClientRegistrationController::is_valid_redirect_uri()`.
> - **Do not silently rate-limit — return 429 with RFC-6749-shaped
>   error JSON.**
> - **Do not touch Feature 011's DECISIONS or its column-width
>   invariants.** Reuse them; do not supersede.
> - **Every task must leave PHPStan level 8 + PHPCS individually green
>   before moving to the next.** Composer scripts `phpcs` and `phpstan`
>   both exist in composer.json.
> - **BerlinDB Schema `$columns` MUST match exactly the type/length/default
>   spec in the tables above.** Any deviation fires ALTER TABLE on
>   production installs — the reviewer MUST verify with
>   `SHOW CREATE TABLE {name}` on any install with existing OAuth data
>   before merge (there won't be any for a fresh feature, but the check
>   is standing policy).
> - **Grep after every task** for stale references to removed constants
>   / classes. The Final full-repo audit at the bottom MUST return zero
>   matches.
> - **Follow the phantom-version guard `use` collision rule
>   (DEC-BERLINDB-SUBCLASS-NO-USE-COLLISION from Feature 011)**: every
>   Table subclass extends `\BerlinDB\Database\Kern\Table` via
>   leading-`\` FQN — do NOT add `use BerlinDB\Database\Kern\Table;`.

```markdown
# 3. Plan + guard + security
/speckit.memory-md.plan-with-memory
/speckit.architecture-guard.governed-plan
/speckit.security-review.plan

# 4. Tasks + guard
/speckit.tasks
/speckit.architecture-guard.governed-tasks

# 5. Implement + quality checks
/speckit.architecture-guard.governed-implement
composer dump-autoload
composer run phpcs
composer run phpstan
composer test

# 6. Review + memory + commit
/speckit.analyze
/speckit.architecture-guard.architecture-review
/speckit.security-review.staged
/speckit.memory-md.capture-from-diff
/speckit.git.commit
```

---

## Sourcing decisions — what we rejected and why

| Candidate | Verdict | Reason |
| --- | --- | --- |
| Adapt `royalplugins/royal-mcp` OAuth module | Rejected | Maintainer decision. |
| `league/oauth2-server` v9.x | Rejected | Requires PHP 8.2+; plugin floor is 8.1. |
| `league/oauth2-server` v8.5.x | Rejected | Supports 8.1 but maintenance-only line. Also needs PSR-7 bridge (extra `guzzlehttp/psr7` dep + `WP_REST_Request` conversion glue), still requires hand-written RFC 8414/9728/7591. Net cost too high for a v1 that ships one grant + refresh. |
| `bshaffer/oauth2-server-php` | Rejected | No PKCE support in current release. Disqualifying. |
| `mehul0810/aculect-ai-companion` direct adaptation | Rejected | Requires PHP 8.2+ via league v9.3. Structural reference only. |
| Vendor royal-mcp's `includes/OAuth/*` files as a sub-package | Rejected | Same as maintainer's royal-mcp decision. |

**Chosen path**: hand-authored pure PHP, ~1,500-2,000 LOC, zero new
composer runtime deps, PHP 8.1 clean, GPL-2.0-or-later, aculect-ai-companion's
folder shape used as a structural reference only (not code-copied).

---

## Manual Verification Checklist

### TASK-1 — Three new BerlinDB tables
- [ ] `includes/Database/{OAuthClients,OAuthTokens,OAuthAuthCodes}/` all
      contain 4 files each (Schema, Query, Row, Table) extending
      `\BerlinDB\Database\Kern\*` via leading-`\` FQN.
- [ ] Every Table subclass has the phantom-version guard override.
- [ ] Fresh activation: `SHOW TABLES LIKE 'wp_acrossai_mcp_oauth_%'`
      returns three rows.
- [ ] `SHOW CREATE TABLE wp_acrossai_mcp_oauth_clients` shows
      `client_secret_hash char(64) DEFAULT NULL` — NULL is critical for
      public clients.
- [ ] `SHOW CREATE TABLE wp_acrossai_mcp_oauth_auth_codes` shows
      `code_challenge char(43) NOT NULL DEFAULT ''` matching CliAuthLog's
      invariant byte-for-byte.
- [ ] `SHOW CREATE TABLE wp_acrossai_mcp_oauth_tokens` shows
      `token_type varchar(16) NOT NULL DEFAULT ''` and both
      key `token_type` + key `expires_at` present.
- [ ] Phantom-guard smoke: `wp db query "DROP TABLE
      wp_acrossai_mcp_oauth_tokens"` then deactivate + reactivate →
      table exists again, `_db_version` option unchanged.

### TASK-2 — OAuth core
- [ ] `includes/OAuth/{DiscoveryController,AuthorizationController,TokenController,ClientRegistrationController,TokenValidator,PKCE}.php`
      and `includes/OAuth/Repositories/*.php` and `includes/OAuth/Security/*.php`
      all exist. All singletons with private constructors, `instance()`,
      zero constructor hook wiring.
- [ ] `PKCE::verify_s256` matches RFC 7636 Appendix B test vectors.
- [ ] `TokenValidator::authenticate` recursion guard is present.
- [ ] `AuthorizationController::handle_get` rejects `code_challenge_method
      != 'S256'` with a `error=invalid_request` redirect.
- [ ] `TokenController::handle_authorization_code` uses
      `AuthCodeRepository::consume_atomic` and returns `invalid_grant`
      on second call with the same code.
- [ ] `TokenController::handle_refresh_token` revokes the old refresh
      token before issuing new pair.

### TASK-3 — Connector profile framework
- [ ] `AbstractConnectorProfile` abstract methods match the contract
      in this doc verbatim.
- [ ] `ConnectorProfileRegistry` is a singleton, memoizes the filter
      result within a request, dedupes by slug.

### TASK-4 — AI Connectors tab
- [ ] `admin/Partials/ServerTabs/AIConnectorsTab.php` extends
      `AbstractServerTab` with `priority() === 35`.
- [ ] `Registry::all_tabs()` includes `new AIConnectorsTab()` in the
      seed at the position matching priority 35 (between ClientsTab and
      WpCliTab).
- [ ] Navigate to `admin.php?page=acrossai_mcp_manager&action=edit&server=1&tab=ai-connectors` —
      tab renders. If zero profiles registered, empty-state notice
      appears.

### TASK-5 — Main.php hook wiring
- [ ] `Main::bootstrap_database_tables()` instantiates all six Table
      subclasses (three from Feature 011 + three from Feature 021).
- [ ] `Main::define_admin_hooks()` contains the two new
      `rest_api_init` registrations for
      `ClientRegistrationController::register_dcr_route` and
      `register_admin_generate_route`.
- [ ] `Main::define_public_hooks()` contains the four new registrations
      for `OAuthRouter` + `TokenValidator` + `Cleanup`.
- [ ] No `add_action` / `add_filter` calls in any new OAuth class
      constructor (Architecture Principle A1).

### TASK-6 — Activator + Deactivator + uninstall
- [ ] `Activator::activate()` calls `maybe_upgrade` on all six tables
      + schedules the daily cron.
- [ ] `Deactivator::deactivate()` clears the daily cron.
- [ ] `uninstall.php` respects `acrossai_mcp_uninstall_delete_data`
      option — when true, drops the three new tables.

### TASK-7 — Consent template
- [ ] `templates/oauth/consent.php` renders self-contained HTML with
      no admin bar / no theme header.
- [ ] Form uses `wp_nonce_field( 'acrossai_mcp_manager_oauth_authorize' )`.
- [ ] `noindex, nofollow` meta present.

### TASK-8 — Version bumps
- [ ] `acrossai-mcp-manager.php` `Version` header is `0.1.0`.
- [ ] `readme.txt` Unreleased changelog contains the OAuth 2.1 entry.

### TASK-9 — Docs
- [ ] `docs/extending-connector-profiles.md` exists and documents the
      filter + abstract class contract with a worked example.
- [ ] `docs/planings-tasks/README.md` lists `021-oauth-2-1-implementation.md`.

### TASK-10 — Unit tests
- [ ] `composer test` (or `vendor/bin/phpunit tests/phpunit/OAuth/`)
      passes all six new test files.
- [ ] `AuthCodeConsumeAtomicTest` includes the `$wpdb->last_query`
      predicate assertion.

### TASK-11 — Memory hygiene
- [ ] DECISIONS.md contains the five new Active entries.
- [ ] INDEX.md lists them + the WORKLOG milestone entry.
- [ ] ARCHITECTURE.md captures tab-vs-filter distinction.

### Final full-repo audit (blocker before merge)

```bash
grep -rEn 'league\\OAuth2|GuzzleHttp\\Psr7|phpseclib|firebase\\JWT' \
    --include='*.php' \
    includes/ admin/ public/

grep -rEn 'add_action\s*\(|add_filter\s*\(' \
    --include='*.php' \
    includes/OAuth/ includes/Connectors/

grep -rEn 'code_challenge_method\s*!==?\s*.plain' \
    --include='*.php' \
    includes/OAuth/
```

- [ ] First grep returns zero matches — no accidental library imports
      snuck in.
- [ ] Second grep returns zero matches — Architecture Principle A1
      compliance (no constructor hook wiring in new code).
- [ ] Third grep returns at least one match — proves the S256-mandatory
      check exists.

### Quality gates (all must be green before commit)
- [ ] PHPStan level 8 — zero errors.
- [ ] PHPCS — zero errors.
- [ ] `composer test` — PHPUnit all tests pass (Feature 011's + Feature
      021's).
- [ ] `composer dump-autoload` — succeeds with zero warnings.
- [ ] `SHOW TABLES LIKE 'wp_acrossai_mcp_%'` on a clean install returns
      exactly six rows.
- [ ] `SELECT option_name, option_value FROM wp_options WHERE
      option_name LIKE 'acrossai_mcp%_db_version'` returns exactly six
      rows.
- [ ] `wp cron event list | grep acrossai_mcp_manager_oauth_cleanup`
      shows one scheduled event.
- [ ] `wp rewrite list | grep -E '\\.well-known|authorize|token'` shows
      four registered rules.

---

## End-to-end acceptance (out of Feature 021 scope, blocked on companion Feature 002)

- Install `acrossai-claude-connectors` (companion plugin — Feature 002 of
  that repo).
- Open `admin.php?page=acrossai_mcp_manager&action=edit&server=1&tab=ai-connectors`.
- Click "Generate credentials" for Claude — client_id and client_secret
  appear.
- In Claude UI: Settings → Connectors → Add custom connector → paste
  site URL → expand Advanced → paste client_id + client_secret → Add.
- Login to WP if prompted → consent screen shown → Approve.
- Claude receives auth code, exchanges at /token, MCP calls succeed
  under the OAuth-authenticated user.
- Wait past `expires_in` (3600s) — Claude refreshes automatically.
- Revoke via a test SQL update `UPDATE wp_acrossai_mcp_oauth_tokens SET
  revoked=1 WHERE id=X` — next MCP call returns 401 with
  `WWW-Authenticate: Bearer resource_metadata=<discovery URL>`.

Operational notes:
- Anthropic egress: `160.79.104.0/21`. Allowlist in production WAF.
- CORS not required (Claude traffic originates from Anthropic's cloud,
  not the browser).
- 10s SLA on token exchange, 30s on refresh.
- HTTPS with valid CA cert mandatory in production; localhost allowed
  in dev.
